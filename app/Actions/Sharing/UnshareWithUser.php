<?php

namespace App\Actions\Sharing;

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UnshareWithUser
{
    public function handle(User $actor, Node $node, User $recipient): void
    {
        Gate::forUser($actor)->authorize('share', $node);

        $node->sharedWith()->detach($recipient->id);
    }
}
