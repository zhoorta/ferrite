<?php

namespace App\Support;

use App\Enums\NodeType;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * What a folder holds: `nodes.size` is the bytes of the files inside it (subfolders included, the trash and old
 * versions left out) and `nodes.files_count` how many files that is. Reading either costs nothing.
 *
 * Every action that adds, removes, resizes or moves a file moves the difference up the folders above it with
 * `add()`, the way `used_bytes` follows the owner's quota. A folder in the trash keeps its own totals (what is
 * inside it), and the folders above it no longer count it. `recount()` rebuilds everything from the files, and
 * `ferrite:folders:recount` is its command.
 */
class FolderSizes
{
    /**
     * How many files a node stands for: one for a file, what is inside for a folder.
     */
    public static function filesIn(Node $node): int
    {
        return $node->isFile() ? 1 : (int) $node->files_count;
    }

    /**
     * Add (or, negative, remove) bytes and files to a folder and every folder above it. The climb stops after a
     * folder in the trash, because nothing above it counts what is inside.
     */
    public function add(?int $folderId, int $bytes, int $files = 0): void
    {
        if ($folderId === null || ($bytes === 0 && $files === 0)) {
            return;
        }

        $rows = DB::select(
            'with recursive up (id, parent_id, trashed_at, depth) as (
                select id, parent_id, trashed_at, 0 from nodes where id = ?
                union all
                select n.id, n.parent_id, n.trashed_at, up.depth + 1 from nodes n join up on n.id = up.parent_id
            ) select id, trashed_at from up order by depth',
            [$folderId],
        );

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row->id;

            if ($row->trashed_at !== null) {
                break;
            }
        }

        if ($ids === []) {
            return;
        }

        $this->shift($ids, 'size', $bytes);
        $this->shift($ids, 'files_count', $files);
    }

    /**
     * @param  list<int>  $ids
     */
    private function shift(array $ids, string $column, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $rows = DB::table('nodes')->whereIn('id', $ids);
        $amount = abs($delta);

        if ($delta > 0) {
            $rows->increment($column, $amount);

            return;
        }

        // Never below zero, whatever drifted: rows that cannot take it go to zero first.
        (clone $rows)->where($column, '<', $amount)->update([$column => 0]);
        $rows->where($column, '>=', $amount)->decrement($column, $amount);
    }

    /**
     * Compute every folder's totals from the files and compare them with what is stored.
     *
     * @return array{checked: int, wrong: int} folders looked at, and folders with a wrong size or count (fixed unless $fix is false)
     */
    public function recount(bool $fix = true, ?int $ownerId = null): array
    {
        $checked = 0;
        $wrong = 0;

        $owners = $ownerId !== null ? [$ownerId] : Node::query()->distinct()->pluck('owner_id')->all();

        foreach ($owners as $owner) {
            $nodes = Node::query()->where('owner_id', $owner)->get(['id', 'parent_id', 'type', 'size', 'files_count', 'trashed_at']);

            $children = [];
            $folders = [];

            foreach ($nodes as $node) {
                $children[$node->parent_id ?? 0][] = $node;

                if ($node->type === NodeType::Folder) {
                    $folders[$node->id] = $node;
                }
            }

            /** @var array<int, array{int, int}> $totals bytes and files per folder */
            $totals = [];
            $total = function (int $id) use (&$total, &$totals, $children, $folders): array {
                if (isset($totals[$id])) {
                    return $totals[$id];
                }

                $bytes = 0;
                $files = 0;

                foreach ($children[$id] ?? [] as $child) {
                    if ($child->trashed_at !== null) {
                        continue;
                    }

                    if (isset($folders[$child->id])) {
                        [$childBytes, $childFiles] = $total($child->id);
                        $bytes += $childBytes;
                        $files += $childFiles;
                    } else {
                        $bytes += $child->size;
                        $files++;
                    }
                }

                return $totals[$id] = [$bytes, $files];
            };

            foreach ($folders as $id => $folder) {
                $checked++;
                [$bytes, $files] = $total($id);

                if ($folder->size !== $bytes || $folder->files_count !== $files) {
                    $wrong++;

                    if ($fix) {
                        DB::table('nodes')->where('id', $id)->update(['size' => $bytes, 'files_count' => $files]);
                    }
                }
            }
        }

        return ['checked' => $checked, 'wrong' => $wrong];
    }
}
