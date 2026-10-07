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
     *     largest: list<array{id: int, parent_id: int|null, name: string, size: int, mime: string|null}>,
     *     folders: list<array{id: int, name: string, bytes: int}>,
     *     loose: int,
     *     saved: int,
     * }
     */
    public function forUser(User $user): array
    {
        $nodes = Node::query()
            ->where('owner_id', $user->id)
            ->get(['id', 'parent_id', 'type', 'name', 'size', 'mime', 'trashed_at', 'disk_id', 'path'])
            ->keyBy('id');

        $trashed = [];
        $root = [];
        $resolve = function (Node $node) use ($nodes, &$trashed, &$root): void {
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
                $top = $link->parent_id === null || $nodes->get($link->parent_id) === null ? $link->id : $top;
                $trashed[$link->id] = $inTrash;
                $root[$link->id] = $top;
            }
        };

        $kinds = array_fill_keys(self::KINDS, ['bytes' => 0, 'count' => 0]);
        $folderBytes = [];
        $active = 0;
        $trash = 0;
        $files = 0;
        $loose = 0;
        $largest = [];

        foreach ($nodes as $node) {
            if ($node->type !== NodeType::File) {
                continue;
            }

            $resolve($node);

            if ($trashed[$node->id]) {
                $trash += $node->size;

                continue;
            }

            $files++;
            $active += $node->size;

            $kind = self::kind($node->mime);
            $kinds[$kind]['bytes'] += $node->size;
            $kinds[$kind]['count']++;

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
                'size' => $node->size,
                'mime' => $node->mime,
            ], array_slice($largest, 0, 10)),
            'folders' => collect($folderBytes)
                ->take(8)
                ->map(fn (int $bytes, int $id) => ['id' => $id, 'name' => $nodes[$id]->name, 'bytes' => $bytes])
                ->values()
                ->all(),
            'loose' => $loose,
            'saved' => $this->dedupSaved($nodes->all()),
        ];
    }

    /**
     * Bytes stored on each disk, counting a blob shared by several nodes once, keyed by disk id.
     * The per-node sum (what quotas see) is larger whenever deduplication kicked in.
     *
     * @return array<int, int>
     */
    public function physicalByDisk(): array
    {
        return DB::table('nodes')
            ->selectRaw('disk_id, sum(size) as bytes')
            ->fromSub(
                DB::table('nodes')
                    ->selectRaw('disk_id, path, max(size) as size')
                    ->where('type', NodeType::File->value)
                    ->whereNotNull('path')
                    ->groupBy('disk_id', 'path'),
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
