<?php

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RenameNode
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Node $node, string $name): Node
    {
        Gate::forUser($actor)->authorize('update', $node);

        $name = NodeName::normalize($name);

        NodeName::assertAvailable($node->owner_id, $node->parent_id, $name, $node->id);

        $node->update(['name' => $name]);

        return $node;
    }
}
