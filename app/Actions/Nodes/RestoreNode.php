<?php

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreNode
{
    /**
     * Put a trashed node back where it was. If the folder it lived in is gone or trashed too, it
     * goes to the root; if its name is taken meanwhile, a " (2)", " (3)"... suffix is added.
     */
    public function handle(User $actor, Node $node): Node
    {
        Gate::forUser($actor)->authorize('restore', $node);

        if ($node->trashed_at === null) {
            return $node;
        }

        $parent = $node->parent_id === null ? null : Node::find($node->parent_id);
        $parentId = $parent !== null && ! $parent->isTrashed() ? $parent->id : null;

        $name = NodeName::available($node->owner_id, $parentId, $node->name, $node->isFile(), $node->id);

        $node->forceFill(['parent_id' => $parentId, 'name' => $name, 'trashed_at' => null])->save();

        return $node;
    }
}
