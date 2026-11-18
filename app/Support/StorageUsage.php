<?php

namespace App\Support;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Where one user's space goes: by kind of file, the biggest files and top-level folders, the trash,
 * and what deduplication saves. Built in memory from the owner's nodes (ids, sizes, mime), which is
 * plenty for one person or a small team.
 */
class StorageUsage
{
    /** Kinds shown in the breakdown, in display order. */
    public const KINDS = ['image', 'video', 'audio', 'document', 'archive', 'other'];

    private const ARCHIVES = [
        'application/zip', 'application/x-zip-compressed', 'application/gzip', 'application/x-gzip',
        'application/x-tar', 'application/x-7z-compressed', 'application/vnd.rar', 'application/x-rar-compressed',
        'application/x-bzip2', 'application/x-xz',
    ];

    private const DOCUMENT_PREFIXES = [
        'application/msword', 'application/vnd.ms-', 'application/vnd.openxmlformats-officedocument',
        'application/vnd.oasis.opendocument', 'application/rtf',
    ];

    public static function kind(?string $mime): string
    {
        $mime = $mime === null ? null : strtolower(trim(explode(';', $mime)[0]));

        return match (true) {
            $mime === null => 'other',
            in_array($mime, self::ARCHIVES, true) => 'archive',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            FileKind::of($mime) !== null => 'document',
            collect(self::DOCUMENT_PREFIXES)->contains(fn (string $prefix) => str_starts_with($mime, $prefix)) => 'document',
            default => 'other',
        };
    }

    /**
     * @return array{
     *     used: int,
     *     quota: int|null,
     *     active: int,
     *     trash: int,
     *     files: int,
     *     kinds: array<string, array{bytes: int, count: int}>,
     *     largest: list<array{id: int, parent_id: int|null, name: string, path: string, size: int, mime: string|null}>,
     *     folders: list<array{id: int, name: string, bytes: int}>,
     *     loose: int,
     *     saved: int,
     *     versions: int,
     *     largest_folders: list<array{id: int, name: string, path: string, bytes: int, files: int}>,
     * }
     */
    public function forUser(User $user, ?Node $within = null, int $limit = 0): array
    {
        $nodes = Node::query()
            ->where('owner_id', $user->id)
            ->get(['id', 'parent_id', 'type', 'name', 'size', 'files_count', 'mime', 'trashed_at', 'disk_id', 'path'])
            ->keyBy('id');

        // Within a folder, only what is below it counts, and its direct children take the place of the top level.
        $inside = $within === null ? null : $this->below($nodes->all(), $within->id);
        $topParent = $within?->id;

        $trashed = [];
        $root = [];
        $resolve = function (Node $node) use ($nodes, $within, &$trashed, &$root): void {
            // Walk up once per node, remembering what each ancestor resolves to.
            $chain = [];
            $current = $node;
            while ($current !== null && ! isset($trashed[$current->id])) {
                $chain[] = $current;
                $current = $current->parent_id === null ? null : $nodes->get($current->parent_id);
            }

            $inTrash = $current !== null && $trashed[$current->id];
            $top = $current === null ? null : $root[$current->id];

            foreach (array_reverse($chain) as $link) {
                $inTrash = $inTrash || $link->trashed_at !== null;
                $top = ($within === null ? ($link->parent_id === null || $nodes->get($link->parent_id) === null) : $link->parent_id === $within->id) ? $link->id : $top;
                $trashed[$link->id] = $inTrash;
                $root[$link->id] = $top;
            }
        };

        $kindBytes = array_fill_keys(self::KINDS, 0);
        $kindCounts = array_fill_keys(self::KINDS, 0);
        $folderBytes = [];
        $active = 0;
        $trash = 0;
        $files = 0;
        $loose = 0;
        $largest = [];
        $fileIds = [];

        foreach ($nodes as $node) {
            if ($node->type !== NodeType::File || ($inside !== null && ! isset($inside[$node->id]))) {
                continue;
            }

            $fileIds[] = $node->id;
            $resolve($node);

            if ($trashed[$node->id]) {
                $trash += $node->size;

                continue;
            }

            $files++;
            $active += $node->size;

            $kind = self::kind($node->mime);
            $kindBytes[$kind] += $node->size;
            $kindCounts[$kind]++;

            // Files that sit directly at the top are not part of any folder.
            if ($root[$node->id] === $node->id) {
                $loose += $node->size;
            } else {
                $folderBytes[$root[$node->id]] = ($folderBytes[$root[$node->id]] ?? 0) + $node->size;
            }

            $largest[] = $node;
        }

        usort($largest, fn (Node $a, Node $b) => $b->size <=> $a->size);
        arsort($folderBytes);

        // The way to a node from the top (or from the folder asked about), with the names as stored.
        $pathOf = function (Node $node) use ($nodes, $topParent): string {
            $parts = [$node->name];

            for ($current = $node; $current->parent_id !== null && $current->parent_id !== $topParent && ($parent = $nodes->get($current->parent_id)) !== null; $current = $parent) {
                $parts[] = $parent->name;
            }

            return implode('/', array_reverse($parts));
        };

        // The folders that hold the most, at any depth, from the sizes the actions keep (docs/folder-sizes.md).
        $bigFolders = [];

        foreach ($nodes as $node) {
            if ($node->type !== NodeType::Folder || $node->size === 0 || ($inside !== null && ! isset($inside[$node->id])) || $node->id === $within?->id) {
                continue;
            }

            $resolve($node);

            if (! $trashed[$node->id]) {
                $bigFolders[] = $node;
            }
        }

        usort($bigFolders, fn (Node $a, Node $b) => $b->size <=> $a->size);

        $kinds = [];

        foreach (self::KINDS as $kind) {
            $kinds[$kind] = ['bytes' => $kindBytes[$kind], 'count' => $kindCounts[$kind]];
        }

        return [
            'used' => $user->used_bytes,
            'quota' => $user->quota_bytes,
            'active' => $active,
            'trash' => $trash,
            'files' => $files,
            'kinds' => $kinds,
            'largest' => array_map(fn (Node $node) => [
                'id' => $node->id,
                'parent_id' => $node->parent_id,
                'name' => $node->name,
                'path' => $pathOf($node),
                'size' => $node->size,
                'mime' => $node->mime,
            ], array_slice($largest, 0, $limit ?: 10)),
            'largest_folders' => array_map(fn (Node $node) => [
                'id' => $node->id,
                'name' => $node->name,
                'path' => $pathOf($node),
                'bytes' => $node->size,
                'files' => $node->files_count,
            ], array_slice($bigFolders, 0, $limit ?: 8)),
            'versions' => $this->versionBytes($user, $inside === null ? null : $fileIds),
            'folders' => array_values(collect($folderBytes)
                ->take($limit ?: 8)
                ->map(fn (int $bytes, int $id) => ['id' => $id, 'name' => $nodes[$id]->name, 'bytes' => $bytes])
                ->all()),
            'loose' => $loose,
            'saved' => $this->dedupSaved($inside === null ? $nodes->all() : $nodes->only(array_keys($inside))->all()),
        ];
    }

    /**
     * The ids of everything below a folder (not the folder itself), as keys.
     *
     * @param  array<int, Node>  $nodes
     * @return array<int, true>
     */
    private function below(array $nodes, int $folderId): array
    {
        $children = [];

        foreach ($nodes as $node) {
            $children[$node->parent_id ?? 0][] = $node->id;
        }

        $inside = [];
        $queue = [$folderId];

        while ($queue !== []) {
            foreach ($children[array_shift($queue)] ?? [] as $id) {
                $inside[$id] = true;
                $queue[] = $id;
            }
        }

        return $inside;
    }

    /**
     * The space earlier versions of replaced files take (they count against the quota, see docs/versions.md): all of the
     * user's, or those of the given files.
     *
     * @param  list<int>|null  $fileIds
     */
    private function versionBytes(User $user, ?array $fileIds): int
    {
        if ($fileIds === null) {
            return (int) DB::table('node_versions')
                ->join('nodes', 'nodes.id', '=', 'node_versions.node_id')
                ->where('nodes.owner_id', $user->id)
                ->sum('node_versions.size');
        }

        $bytes = 0;

        foreach (array_chunk($fileIds, 500) as $chunk) {
            $bytes += (int) DB::table('node_versions')->whereIn('node_id', $chunk)->sum('size');
        }

        return $bytes;
    }

    /**
     * Bytes stored on each disk, counting a blob shared by several nodes once, keyed by disk id.
     * The per-node sum (what quotas see) is larger whenever deduplication kicked in.
     *
     * @return array<int, int>
     */
    public function physicalByDisk(): array
    {
        // The files' blobs and those of their earlier versions (docs/versions.md).
        return DB::table('nodes')
            ->selectRaw('disk_id, sum(size) as bytes')
            ->fromSub(
                DB::table('nodes')
                    ->selectRaw('disk_id, path, max(size) as size')
                    ->where('type', NodeType::File->value)
                    ->whereNotNull('path')
                    ->groupBy('disk_id', 'path')
                    ->union(DB::table('node_versions')->selectRaw('disk_id, path, max(size) as size')->groupBy('disk_id', 'path')),
                'blobs',
            )
            ->groupBy('disk_id')
            ->pluck('bytes', 'disk_id')
            ->map(fn ($bytes) => (int) $bytes)
            ->all();
    }

    /**
     * Space the user would use without deduplication, minus what is really stored.
     *
     * @param  array<int, Node>  $nodes
     */
    private function dedupSaved(array $nodes): int
    {
        $seen = [];
        $saved = 0;

        foreach ($nodes as $node) {
            if ($node->type !== NodeType::File || $node->path === null) {
                continue;
            }

            $key = $node->disk_id.'|'.$node->path;
            isset($seen[$key]) ? $saved += $node->size : $seen[$key] = true;
        }

        return $saved;
    }
}
