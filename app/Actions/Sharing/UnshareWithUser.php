<?php

namespace App\Actions\Sharing;

use App\Enums\ActivityAction;
use App\Models\Node;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Support\Facades\Gate;

class UnshareWithUser
{
    public function handle(User $actor, Node $node, User $recipient): void
    {
        Gate::forUser($actor)->authorize('share', $node);

        if ($node->sharedWith()->detach($recipient->id) > 0) {
            ActivityLog::record(ActivityAction::Unshared, $node, $actor, ['user' => $recipient->name]);
        }
    }
}
