<?php

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\CreateFolder;
use App\Actions\Nodes\EnsureFolderPath;
use App\Actions\Nodes\MoveNode;
use App\Actions\Nodes\NodeName;
use App\Actions\Nodes\PurgeNode;
use App\Actions\Nodes\RenameNode;
use App\Actions\Nodes\RestoreNode;
use App\Actions\Nodes\TrashNode;
use App\Enums\NodeType;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Node;
use App\Support\NodeResponder;
use App\Support\NodeSearch;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    private const PAGE = 1000;

    public function __construct(private NodeResponder $responder) {}

    /**
     * Move a file or a folder (with what is in it) to the trash (deleting for good is `purge`): it can still be restored from the
     * web app. The token's own folder cannot be trashed through it (403), and a node outside it is a 404.
     */
    public function trash(Request $request, Node $node, TrashNode $trash): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();

        abort_unless($token->reaches($node), 404);
        abort_if($node->id === $token->folder_id, 403, 'A token cannot trash its own folder.');

        $trash->handle($request->user(), $node, ['token' => $token->name]);

        return response()->json(['id' => $node->id, 'trashed' => true]);
    }

    /**
     * Change a node, one thing per request. `folder` moves a file or a folder (with what is in it) to another folder of the token's folder, keeping its
     * name: the path of the destination relative to the token's folder ("" for the folder itself); missing folders on the
     * way are created, like uploads. `name` renames a file or a folder inside the token's folder (never the token's own
     * folder). Neither replaces: a name already taken there is a 409 and nothing changes.
     * Needs a write token.
     */
    public function update(Request $request, Node $node, EnsureFolderPath $ensure, MoveNode $mover, RenameNode $renamer): JsonResponse
    {
        $data = $request->validate([
            'folder' => ['nullable', 'string', 'max:2048'],
            'name' => ['string', 'max:255'],
        ]);

        if ($request->has('folder') === $request->has('name')) {
            throw ValidationException::withMessages(['name' => __('Send either a folder to move to or a name to rename to.')]);
        }

        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();
        $root = $token->rootFolder();

        abort_unless($token->reaches($node), 404);

        if ($request->has('name')) {
            abort_if($node->id === $root?->id, 404);

            $name = NodeName::normalize($data['name']);

            if (NodeName::taken($node->owner_id, $node->parent_id, $name, $node->id)) {
                return response()->json(['error' => 'exists'], 409);
            }

            $renamer->handle($request->user(), $node, $name, ['token' => $token->name]);
        } else {
            abort_if($node->id === $root?->id, 404);

            $folder = trim((string) $data['folder'], '/');
            $segments = $folder === '' ? [] : explode('/', $folder);
            $destination = $ensure->handle($request->user(), $root, $segments);

            // A missing destination was just created and is empty, so a clash can only be with something that was there.
            if ($node->parent_id !== $destination?->id && NodeName::taken($node->owner_id, $destination?->id, $node->name, $node->id)) {
                return response()->json(['error' => 'exists'], 409);
            }

            $mover->handle($request->user(), $node, $destination, ['token' => $token->name]);
        }

        $node = $node->fresh();

        return response()->json(['id' => $node->id, 'name' => $node->name, 'path' => $this->relativePath($node, $root?->id)]);
    }

    /**
     * What was thrown away from inside the token's folder (the whole drive for a whole-drive token): the items that sit
     * at the top of the trash, newest first, with the path they had. Needs the trash ability, which also lets the token
     * restore them; purging stays with the signed-in owner.
     */
    private function trashed(Request $request, ApiToken $token, ?Node $root): JsonResponse
    {
        abort_unless($token->can('trash'), 403, 'This token cannot do that.');

        $items = Node::query()
            ->whereKey(Node::trashRootIds($request->user()))
            ->orderByDesc('trashed_at')
            ->get()
            ->filter(fn (Node $node) => $this->wasInside($node, $root))
            ->take(self::PAGE)
            ->values();

        return response()->json([
            'data' => $items->map(fn (Node $node) => [
                'id' => $node->id,
                'name' => $node->name,
                'type' => $node->isFolder() ? 'folder' : 'file',
                'path' => $this->relativePath($node, $root?->id),
                'size' => $node->size,
                'file_count' => $node->isFolder() ? $node->files_count : null,
                'sha256' => $node->isFile() ? $node->sha256 : null,
                'mtime' => $node->updated_at?->timestamp,
                'mime' => $node->isFile() ? $node->mime : null,
                'trashed_at' => $node->trashed_at?->timestamp,
            ])->values(),
            'next_cursor' => null,
        ]);
    }

    /**
     * Put a trashed item back where it was. A token limited to a folder only restores what was inside it and only when
     * the folder it sat in is still there (it never lands outside the token's folder): otherwise 409 `parent_trashed`.
     * A name taken meanwhile gets a " (2)" suffix, as in the web app.
     */
    public function restore(Request $request, Node $node, RestoreNode $restore): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();
        $root = $token->rootFolder();

        abort_unless($node->owner_id === $token->tokenable_id && $node->trashed_at !== null && $this->wasInside($node, $root), 404);

        if ($root !== null) {
            $parent = $node->parent;

            if ($parent === null || $parent->isTrashed()) {
                return response()->json(['error' => 'parent_trashed'], 409);
            }
        }

        $node = $restore->handle($request->user(), $node, ['token' => $token->name])->fresh();

        return response()->json(['id' => $node->id, 'name' => $node->name, 'path' => $this->relativePath($node, $root?->id), 'restored' => true]);
    }

    /**
     * Delete a trashed item for good, with everything in it and its files' blobs: this cannot be undone. Needs the trash
     * ability and the item must be at the top of the trash and have been inside the token's folder (404 otherwise).
     */
    public function purge(Request $request, Node $node, PurgeNode $purge): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();
        $root = $token->rootFolder();

        abort_unless($node->owner_id === $token->tokenable_id && $node->trashed_at !== null && $this->wasInside($node, $root), 404);

        $id = $node->id;
        $purge->handle($request->user(), $node, ['token' => $token->name]);

        return response()->json(['id' => $id, 'purged' => true]);
    }

    /**
     * Empty the trash of what was inside the token's folder (the whole drive for a whole-drive token), for good.
     * Answers how many top-level items went.
     */
    public function emptyTrash(Request $request, PurgeNode $purge): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();
        $root = $token->rootFolder();
        $count = 0;

        foreach (Node::query()->whereKey(Node::trashRootIds($request->user()))->get() as $node) {
            // An earlier purge may already have removed it as part of its tree.
            if ($this->wasInside($node, $root) && Node::query()->whereKey($node->id)->exists()) {
                $purge->handle($request->user(), $node, ['token' => $token->name]);
                $count++;
            }
        }

        return response()->json(['purged' => $count]);
    }

    /** Whether the node sits below the token's folder (any node of the drive for a whole-drive token). */
    private function wasInside(Node $node, ?Node $root): bool
    {
        return $root === null || ($node->id !== $root->id && in_array($root->id, $node->selfAndAncestorIds(), true));
    }

    /** The file's path below the token's folder, with the names as stored. */
    private function relativePath(Node $file, ?int $rootId): string
    {
        $parts = [$file->name];

        for ($parent = $file->parent; $parent !== null && $parent->id !== $rootId; $parent = $parent->parent) {
            $parts[] = $parent->name;
        }

        return implode('/', array_reverse($parts));
    }

    /**
     * Every file below the token's folder, 1000 per page in id order, with its path relative to
     * that folder. The cursor is the last id of the previous page, so files added meanwhile
     * never shift a page. Trashed files and files in trashed folders are left out.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'parent' => ['nullable', 'string', 'regex:/^(root|\d+)$/'],
            'q' => ['nullable', 'string', 'max:200'],
            'scope' => ['nullable', 'in:name,content'],
            'trashed' => ['nullable', 'in:1'],
            'type' => ['nullable', 'in:folder'],
        ]);

        $user = $request->user();
        /** @var ApiToken $token */
        $token = $user->currentAccessToken();
        $root = $token->rootFolder();

        if ($request->filled('trashed')) {
            return $this->trashed($request, $token, $root);
        }

        if ($request->filled('q')) {
            return $this->search($request, $root);
        }

        if ($request->filled('parent')) {
            return $this->children($request, $token, $root);
        }

        $tree = $this->folders($user->id, $root?->id);
        $names = collect($tree)->keyBy('id');

        $files = Node::query()
            ->where('owner_id', $user->id)
            ->where('type', NodeType::File)
            ->whereNull('trashed_at')
            ->where('id', '>', (int) $request->query('cursor', 0))
            ->where(function ($query) use ($tree, $root) {
                $query->whereIn('parent_id', array_keys($tree));

                if ($root === null) {
                    $query->orWhereNull('parent_id');
                }
            })
            ->orderBy('id')
            ->limit(self::PAGE + 1)
            ->get();

        $more = $files->count() > self::PAGE;
        $files = $files->take(self::PAGE);

        return response()->json([
            'data' => $files->map(fn (Node $file) => [
                'id' => $file->id,
                'path' => $this->pathOf($file, $names->all(), $root?->id),
                'size' => $file->size,
                'sha256' => $file->sha256,
                'mtime' => $file->updated_at?->timestamp,
                'mime' => $file->mime,
            ])->values(),
            'next_cursor' => $more ? $files->last()->id : null,
        ]);
    }

    /**
     * Search below the token's folder (the whole drive for a whole-drive token), only in the token owner's own files:
     * `scope=name` (default) matches names, files and folders; `scope=content` matches the text of files, each with a
     * plain-text `snippet`, and is empty when content search is off. The limits are the web app's (50 and 30), best
     * match first, with no cursor. Trashed items and anything outside the token's folder never appear.
     */
    private function search(Request $request, ?Node $root): JsonResponse
    {
        $user = $request->user();
        $term = (string) $request->query('q');
        $within = $root?->id;
        $content = $request->query('scope') === 'content';

        $results = $content
            ? app(NodeSearch::class)->contents($user, $term, ownOnly: true, within: $within)
            : app(NodeSearch::class)->search($user, $term, ownOnly: true, within: $within);

        return response()->json([
            'data' => $results->map(function (array $result) use ($root, $content) {
                /** @var Node $node */
                $node = $result['node'];

                return [
                    'id' => $node->id,
                    'name' => $node->name,
                    'type' => $node->isFolder() ? 'folder' : 'file',
                    'path' => $this->relativePath($node, $root?->id),
                    'size' => $node->size,
                    'file_count' => $node->isFolder() ? $node->files_count : null,
                    'sha256' => $node->isFile() ? $node->sha256 : null,
                    'mtime' => $node->updated_at?->timestamp,
                    'mime' => $node->isFile() ? $node->mime : null,
                ] + ($content ? ['snippet' => str_replace(["\x01", "\x02"], '', $result['snippet'])] : []);
            })->values(),
            'next_cursor' => null,
        ]);
    }

    /**
     * The folder a `parent` value names: `root` is the token's folder (null for a whole-drive token, the top of the
     * drive), otherwise the id of a folder the token reaches; anything else is a 404.
     */
    private function parentFolder(string $parent, ApiToken $token, ?Node $root): ?Node
    {
        if ($parent === 'root') {
            return $root;
        }

        $folder = Node::query()->find((int) $parent);
        abort_unless($folder !== null && $folder->isFolder() && $token->reaches($folder), 404);

        return $folder;
    }

    /**
     * Create a folder in `parent` (`root` or a folder id inside the token's folder) with a name. Needs a write token.
     * A name already taken there, by a file or a folder, is `409 {error: "exists", id, type}` so a caller can reuse an
     * existing folder; nothing is replaced.
     */
    public function store(Request $request, CreateFolder $create): JsonResponse
    {
        $data = $request->validate([
            'parent' => ['required', 'string', 'regex:/^(root|\d+)$/'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();
        $root = $token->rootFolder();
        $parent = $this->parentFolder($data['parent'], $token, $root);
        $name = NodeName::normalize($data['name']);

        $existing = Node::query()
            ->where('owner_id', $request->user()->id)
            ->where('parent_id', $parent?->id)
            ->whereNull('trashed_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return response()->json(['error' => 'exists', 'id' => $existing->id, 'type' => $existing->isFolder() ? 'folder' : 'file'], 409);
        }

        try {
            $folder = $create->handle($request->user(), $parent, $name, ['token' => $token->name]);
        } catch (QueryException) {
            // A parallel request took the name between the check and the insert.
            return response()->json(['error' => 'exists'], 409);
        }

        return response()->json([
            'id' => $folder->id,
            'name' => $folder->name,
            'type' => 'folder',
            'path' => $this->relativePath($folder, $root?->id),
        ], 201);
    }

    /**
     * One folder level: the direct children (folders and files) of `parent`, which is `root` (the token's folder, or the
     * top of the drive for a whole-drive token) or the id of a folder the token reaches. 1000 per page in id order with
     * the same cursor as the full listing; the caller sorts. Trashed items are left out; anything else is a 404.
     * Each item carries `name` and `type` next to the usual fields, folders have no size, sha256 or mime.
     */
    private function children(Request $request, ApiToken $token, ?Node $root): JsonResponse
    {
        $user = $request->user();
        $folder = $this->parentFolder((string) $request->query('parent'), $token, $root);

        $base = $folder === null || $folder->id === $root?->id ? '' : $this->relativePath($folder, $root?->id).'/';

        $items = Node::query()
            ->where('owner_id', $user->id)
            ->whereNull('trashed_at')
            ->where('parent_id', $folder?->id)
            ->when($request->query('type') === 'folder', fn ($query) => $query->where('type', NodeType::Folder))
            ->where('id', '>', (int) $request->query('cursor', 0))
            ->orderBy('id')
            ->limit(self::PAGE + 1)
            ->get();

        $more = $items->count() > self::PAGE;
        $items = $items->take(self::PAGE);

        return response()->json([
            'data' => $items->map(fn (Node $node) => [
                'id' => $node->id,
                'name' => $node->name,
                'type' => $node->isFolder() ? 'folder' : 'file',
                'path' => $base.$node->name,
                'size' => $node->size,
                'file_count' => $node->isFolder() ? $node->files_count : null,
                'sha256' => $node->isFile() ? $node->sha256 : null,
                'mtime' => $node->updated_at?->timestamp,
                'mime' => $node->isFile() ? $node->mime : null,
            ])->values(),
            'next_cursor' => $more ? $items->last()->id : null,
        ]);
    }

    /**
     * The bytes, with Range, ETag and If-None-Match handled by the responder. A node outside the
     * token's folder is a 404, the same as one that does not exist.
     */
    public function content(Request $request, Node $node): Response
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();

        abort_unless($node->isFile() && $token->reaches($node), 404);

        return $this->responder->file($request, $node);
    }

    /**
     * A small JPEG preview of an image file (256 px on the longest side), with a long-lived `ETag` and `If-None-Match`
     * answered with 304. The same thumbnail the web app shows, made on first use and cached next to the blob (encrypted
     * for an encrypted file). 404 when the file has none: not an image, too big or too many pixels to decode, damaged,
     * or outside the token's folder. Needs only a read token.
     */
    public function thumbnail(Request $request, Node $node): Response
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();

        abort_unless($node->isFile() && $token->reaches($node), 404);

        return $this->responder->thumbnail($request, $node) ?? abort(404);
    }

    /**
     * The folders under the root (or all of the user's folders), not trashed, as id => [id, parent_id, name].
     *
     * @return array<int, array{id: int, parent_id: int|null, name: string}>
     */
    private function folders(int $ownerId, ?int $rootId): array
    {
        $seed = $rootId === null ? 'parent_id is null' : 'id = ?';
        $bindings = $rootId === null ? [$ownerId] : [$ownerId, $rootId];

        $rows = DB::select(
            "with recursive tree (id, parent_id, name) as (
                select id, parent_id, name from nodes
                where owner_id = ? and type = 'folder' and trashed_at is null and {$seed}
                union all
                select n.id, n.parent_id, n.name from nodes n join tree on n.parent_id = tree.id
                where n.type = 'folder' and n.trashed_at is null
            ) select id, parent_id, name from tree",
            $bindings,
        );

        $folders = [];
        foreach ($rows as $row) {
            $folders[(int) $row->id] = ['id' => (int) $row->id, 'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id, 'name' => $row->name];
        }

        return $folders;
    }

    /**
     * @param  array<int, array{id: int, parent_id: int|null, name: string}>  $folders
     */
    private function pathOf(Node $file, array $folders, ?int $rootId): string
    {
        $parts = [$file->name];
        $id = $file->parent_id;

        while ($id !== null && $id !== $rootId) {
            $parts[] = $folders[$id]['name'];
            $id = $folders[$id]['parent_id'];
        }

        return implode('/', array_reverse($parts));
    }
}
