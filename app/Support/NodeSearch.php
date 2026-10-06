<?php

namespace App\Support;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search by name over what a user can see: their own files plus everything below folders shared
 * with them. Trashed items, and items inside trashed folders, are left out.
 */
class NodeSearch
{
    /**
     * @return Collection<int, array{node: Node, path: string, folder_id: int|null}>
     */
    public function search(User $user, string $term, int $limit = 50): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection;
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        $candidates = Node::query()
            ->where(fn ($query) => $query
                ->where('owner_id', $user->id)
                ->orWhereRaw(
                    'id in (with recursive shared (id) as (
                        select node_id from node_user where user_id = ?
                        union all
                        select n.id from nodes n join shared on n.parent_id = shared.id
                    ) select id from shared)',
                    [$user->id],
                ))
            ->whereNull('trashed_at')
            ->whereRaw("lower(name) like ? escape '!'", [$like])
            ->orderByRaw('case when lower(name) = ? then 0 else 1 end', [mb_strtolower($term)])
            ->orderBy('name')
            ->limit($limit * 2)
            ->get();

        // The query only sees the node's own trash flag; an ancestor may be trashed.
        $nodes = $candidates->reject(fn (Node $node) => $node->isTrashed())->take($limit)->values();

        $sharedRoots = array_values(DB::table('node_user')->where('user_id', $user->id)->pluck('node_id')->map(fn ($id) => (int) $id)->all());

        return $nodes->map(function (Node $node) use ($user, $sharedRoots) {
            [$path, $folderId] = $this->location($node, $user, $sharedRoots);

            return ['node' => $node, 'path' => $path, 'folder_id' => $folderId];
        });
    }

    /**
     * Where the node lives, as "Folder / Subfolder", and the id of its folder (null for the root).
     * Folders above a shared one are not the user's to see, so the path starts at the shared folder.
     *
     * @param  list<int>  $sharedRoots
     * @return array{string, int|null}
     */
    private function location(Node $node, User $user, array $sharedRoots): array
    {
        $ids = array_slice(array_reverse($node->selfAndAncestorIds()), 0, -1);

        if ($node->owner_id !== $user->id) {
            $start = null;

            foreach ($ids as $index => $id) {
                if (in_array($id, $sharedRoots, true)) {
                    $start = $index;
                    break;
                }
            }

            // A node shared directly has no visible folder above it.
            $ids = $start === null ? [] : array_slice($ids, $start);
        }

        $names = Node::query()->whereKey($ids)->pluck('name', 'id');
        $labels = array_map(fn (int $id) => $names[$id], $ids);
        $prefix = $node->owner_id === $user->id ? __('My files') : __('Shared with me');

        return [implode(' / ', [$prefix, ...$labels]), $ids === [] ? null : end($ids)];
    }
}
