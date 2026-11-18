<?php

namespace App\Actions\Nodes;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\FolderSizes;
use Illuminate\Support\Facades\Gate;

class TrashNode
{
    /**
     * Only the node itself is marked; its descendants are trashed implicitly.
     */
    /**
     * @param  array<string, mixed>  $meta  extra activity details, e.g. the API token that did it
     */
    public function handle(User $actor, Node $node, array $meta = []): Node
    {
        Gate::forUser($actor)->authorize('trash', $node);

        $wasActive = $node->trashed_at === null;

        $node->forceFill(['trashed_at' => now()])->save();

        if ($wasActive) {
            app(FolderSizes::class)->add($node->parent_id, -(int) $node->size);
        }

        ActivityLog::record(ActivityAction::Trashed, $node, $actor, $meta);

        return $node;
    }
}
