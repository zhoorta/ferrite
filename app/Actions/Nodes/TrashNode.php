<?php

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class TrashNode
{
    /**
     * Only the node itself is marked; its descendants are trashed implicitly.
     */
    public function handle(User $actor, Node $node): Node
    {
        Gate::forUser($actor)->authorize('trash', $node);

        $node->forceFill(['trashed_at' => now()])->save();

        return $node;
    }
}
