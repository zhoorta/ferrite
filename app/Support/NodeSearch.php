<?php

namespace App\Support;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use App\Support\Search\ContentSearch;
use Illuminate\Database\Eloquent\Builder;
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

        $candidates = $this->visibleTo(Node::query(), $user)
            ->whereNull('nodes.trashed_at')
            ->whereRaw("lower(nodes.name) like ? escape '!'", [$like])
            ->orderByRaw('case when lower(nodes.name) = ? then 0 else 1 end', [mb_strtolower($term)])
            ->orderBy('nodes.name')
            ->limit($limit * 2)
            ->get();

        // The query only sees the node's own trash flag; an ancestor may be trashed.
        $nodes = $candidates->reject(fn (Node $node) => $node->isTrashed())->take($limit)->values();

        $sharedRoots = $this->sharedRoots($user);

        return $nodes->map(function (Node $node) use ($user, $sharedRoots) {
            [$path, $folderId] = $this->location($node, $user, $sharedRoots);

            return ['node' => $node, 'path' => $path, 'folder_id' => $folderId];
        });
    }

    /**
     * Files whose text contains the words of $term, best match first, each with a snippet (marked
     * with \x01 and \x02, see ContentSearch::html). Same visibility as the name search. Empty when
     * content search is off or the database cannot do it.
     *
     * @return Collection<int, array{node: Node, path: string, folder_id: int|null, snippet: string}>
     */
    public function contents(User $user, string $term, int $limit = 30): Collection
    {
        $driver = ContentSearch::driver();
        $term = trim($term);

        if ($term === '' || ! ContentSearch::enabled() || $driver === null) {
            return new Collection;
        }

        $query = $driver->match(
            $this->visibleTo(Node::query(), $user)->where('nodes.type', NodeType::File)->whereNull('nodes.trashed_at'),
            $term,
        );

        if ($query === null) {
            return new Collection;
        }

        $candidates = $query->limit($limit * 2)->get();
        $nodes = $candidates->reject(fn (Node $node) => $node->isTrashed())->take($limit)->values();
        $sharedRoots = $this->sharedRoots($user);

        return $nodes->map(function (Node $node) use ($user, $sharedRoots, $driver, $term) {
            [$path, $folderId] = $this->location($node, $user, $sharedRoots);

            return [
                'node' => $node,
                'path' => $path,
                'folder_id' => $folderId,
                'snippet' => $driver->present((string) $node->getAttribute('snippet'), $term),
            ];
        });
    }

    /**
     * Restrict $query to what $user can see: their own nodes and everything below folders shared with them.
     *
     * @param  Builder<Node>  $query
     * @return Builder<Node>
     */
    private function visibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn ($query) => $query
            ->where('nodes.owner_id', $user->id)
            ->orWhereRaw(
                'nodes.id in (with recursive shared (id) as (
                    select node_id from node_user where user_id = ?
                    union all
                    select n.id from nodes n join shared on n.parent_id = shared.id
                ) select id from shared)',
                [$user->id],
            ));
    }

    /**
     * @return list<int>
     */
    private function sharedRoots(User $user): array
    {
        return array_values(DB::table('node_user')->where('user_id', $user->id)->pluck('node_id')->map(fn ($id) => (int) $id)->all());
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
