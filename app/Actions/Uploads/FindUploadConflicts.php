<?php

namespace App\Actions\Uploads;

use App\Actions\Nodes\NodeName;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FindUploadConflicts
{
    /**
     * What an upload of $paths into $parent would run into: files or folders that already have the
     * name of an uploaded file, and existing folders at the top of a dropped folder, which the upload
     * would be merged into. Nothing is changed. Paths are the same as for StartUpload: a name, or a
     * relative path such as "photos/2026/a.jpg".
     *
     * @param  list<string>  $paths
     * @return array{conflicts: list<array{path: string, kind: string}>, merged: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?Node $parent, array $paths): array
    {
        if ($parent !== null) {
            Gate::forUser($actor)->authorize('update', $parent);

            if (! $parent->isFolder() || $parent->isTrashed()) {
                throw ValidationException::withMessages(['parent_id' => __('Files can only be uploaded into an active folder.')]);
            }
        }

        $ownerId = $parent !== null ? $parent->owner_id : $actor->id;
        $children = [];
        $conflicts = [];
        $merged = [];

        foreach ($paths as $path) {
            $segments = explode('/', $path);

            try {
                $segments = array_map(fn (string $segment) => NodeName::normalize($segment), $segments);
            } catch (ValidationException) {
                continue;
            }

            $name = array_pop($segments);
            $current = $parent?->id;
            $exists = true;

            foreach ($segments as $index => $segment) {
                $folder = $this->child($children, $ownerId, $current, $segment);

                if ($folder === null || $folder->type !== NodeType::Folder) {
                    $exists = false;
                    break;
                }

                if ($index === 0) {
                    $merged[$folder->id] = $folder->name;
                }

                $current = $folder->id;
            }

            if (! $exists) {
                continue;
            }

            $existing = $this->child($children, $ownerId, $current, $name);

            if ($existing !== null) {
                $conflicts[] = ['path' => $path, 'kind' => $existing->type === NodeType::Folder ? 'folder' : 'file'];
            }
        }

        return ['conflicts' => $conflicts, 'merged' => array_values($merged)];
    }

    /**
     * The active child of a folder with this name (case-insensitive, as names are unique), looked up once.
     *
     * @param  array<string, Node|null>  $cache
     */
    private function child(array &$cache, int $ownerId, ?int $parentId, string $name): ?Node
    {
        $key = ($parentId ?? 0).'/'.mb_strtolower($name);

        return $cache[$key] ??= Node::query()
            ->where('owner_id', $ownerId)
            ->where('parent_id', $parentId)
            ->whereNull('trashed_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
    }
}
