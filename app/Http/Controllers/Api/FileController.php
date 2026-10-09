<?php

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\EnsureFolderPath;
use App\Actions\Nodes\MoveNode;
use App\Actions\Nodes\NodeName;
use App\Actions\Nodes\TrashNode;
use App\Enums\NodeType;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Node;
use App\Support\NodeResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    private const PAGE = 1000;

    public function __construct(private NodeResponder $responder) {}

    /**
     * Move a file to the trash (never purge): the owner can still restore it from the web app. Folders cannot be
     * trashed through a token, and a node outside the token's folder is a 404.
     */
    public function trash(Request $request, Node $node, TrashNode $trash): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();

        abort_unless($node->isFile() && $token->reaches($node), 404);

        $trash->handle($request->user(), $node, ['token' => $token->name]);

        return response()->json(['id' => $node->id, 'trashed' => true]);
    }

    /**
     * Move a file to another folder of the token's folder, keeping its name: `folder` is the path of the destination
     * relative to the token's folder ("" for the folder itself); missing folders on the way are created, like uploads.
     * Never replaces: a file with that name already there is a 409 and nothing changes. Needs a write token.
     */
    public function move(Request $request, Node $node, EnsureFolderPath $ensure, MoveNode $mover): JsonResponse
    {
        $data = $request->validate(['folder' => ['present', 'nullable', 'string', 'max:2048']]);

        /** @var ApiToken $token */
        $token = $request->user()->currentAccessToken();

        abort_unless($node->isFile() && $token->reaches($node), 404);

        $root = $token->rootFolder();
        $folder = trim((string) $data['folder'], '/');
        $segments = $folder === '' ? [] : explode('/', $folder);
        $destination = $ensure->handle($request->user(), $root, $segments);

        // A missing destination was just created and is empty, so a clash can only be with something that was there.
        if ($node->parent_id !== $destination?->id && NodeName::taken($node->owner_id, $destination?->id, $node->name, $node->id)) {
            return response()->json(['error' => 'exists'], 409);
        }

        $mover->handle($request->user(), $node, $destination, ['token' => $token->name]);

        return response()->json(['id' => $node->id, 'path' => $this->relativePath($node->fresh(), $root?->id)]);
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
        $request->validate(['cursor' => ['nullable', 'integer', 'min:0']]);

        $user = $request->user();
        /** @var ApiToken $token */
        $token = $user->currentAccessToken();
        $root = $token->rootFolder();

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
