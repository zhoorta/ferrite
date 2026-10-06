<?php

namespace App\Actions\Nodes;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Models\User;
use App\Support\ActivityLog;
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

        $from = $node->name;
        $node->update(['name' => $name]);

        if ($from !== $name) {
            ActivityLog::record(ActivityAction::Renamed, $node, $actor, ['from' => $from]);
        }

        return $node;
    }
}
