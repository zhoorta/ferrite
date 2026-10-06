<?php

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MoveNode
{
    /**
     * Move a node into a folder, or to the root when $destination is null.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Node $node, ?Node $destination): Node
    {
        Gate::forUser($actor)->authorize('move', $node);

        if ($destination !== null) {
            Gate::forUser($actor)->authorize('move', $destination);

            if (! $destination->isFolder() || $destination->isTrashed()) {
                throw ValidationException::withMessages(['destination' => __('Choose an active folder.')]);
            }

            if (in_array($node->id, $destination->selfAndAncestorIds(), true)) {
                throw ValidationException::withMessages(['destination' => __('A folder cannot be moved into itself.')]);
            }
        }

        if ($node->parent_id === $destination?->id) {
            return $node;
        }

        if (NodeName::taken($node->owner_id, $destination?->id, $node->name, $node->id)) {
            throw ValidationException::withMessages(['destination' => __('Something with that name already exists there.')]);
        }

        $node->update(['parent_id' => $destination?->id]);

        return $node;
    }
}
