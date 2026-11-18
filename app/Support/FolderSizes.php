<?php

namespace App\Support;

use App\Enums\NodeType;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * The size of a folder: its files and the folders inside, not counting what is in the trash, kept in `nodes.size`.
 *
 * Reading it costs nothing. Every action that adds, removes, resizes or moves a file moves the difference up the
 * folders above it with `add()`, the way `used_bytes` follows the owner's quota. A folder in the trash keeps its own
 * size (what is inside it), and the folders above it no longer count it. `recount()` rebuilds all of them from the
 * files, and `ferrite:folders:recount` is its command; versions are not counted, only the current content.
 */
class FolderSizes
{
    /**
     * Add (or, negative, remove) bytes to a folder and every folder above it. The climb stops after a folder in the
     * trash, because nothing above it counts what is inside.
     */
    public function add(?int $folderId, int $delta): void
    {
        if ($folderId === null || $delta === 0) {
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

        $amount = abs($delta);

        $rows = DB::table('nodes')->whereIn('id', $ids);

        if ($delta > 0) {
            $rows->increment('size', $amount);

            return;
        }

        // Never below zero, whatever drifted: rows that cannot take it go to zero first.
        (clone $rows)->where('size', '<', $amount)->update(['size' => 0]);
        $rows->where('size', '>=', $amount)->decrement('size', $amount);
    }

    /**
     * Compute every folder's size from the files and compare it with what is stored.
     *
     * @return array{checked: int, wrong: int} folders looked at, and folders whose stored size was off (fixed unless $fix is false)
     */
    public function recount(bool $fix = true, ?int $ownerId = null): array
    {
        $checked = 0;
        $wrong = 0;

        $owners = $ownerId !== null ? [$ownerId] : Node::query()->distinct()->pluck('owner_id')->all();

        foreach ($owners as $owner) {
            $nodes = Node::query()->where('owner_id', $owner)->get(['id', 'parent_id', 'type', 'size', 'trashed_at']);

            $children = [];
            $folders = [];

            foreach ($nodes as $node) {
                $children[$node->parent_id ?? 0][] = $node;

                if ($node->type === NodeType::Folder) {
                    $folders[$node->id] = $node;
                }
            }

            $totals = [];
            $total = function (int $id) use (&$total, &$totals, $children, $folders): int {
                if (isset($totals[$id])) {
                    return $totals[$id];
                }

                $sum = 0;

                foreach ($children[$id] ?? [] as $child) {
                    if ($child->trashed_at !== null) {
                        continue;
                    }

                    $sum += isset($folders[$child->id]) ? $total($child->id) : $child->size;
                }

                return $totals[$id] = $sum;
            };

            foreach ($folders as $id => $folder) {
                $checked++;
                $right = $total($id);

                if ($folder->size !== $right) {
                    $wrong++;

                    if ($fix) {
                        DB::table('nodes')->where('id', $id)->update(['size' => $right]);
                    }
                }
            }
        }

        return ['checked' => $checked, 'wrong' => $wrong];
    }
}
