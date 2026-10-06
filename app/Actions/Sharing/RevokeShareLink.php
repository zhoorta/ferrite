<?php

namespace App\Actions\Sharing;

use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RevokeShareLink
{
    public function handle(User $actor, Share $share): Share
    {
        Gate::forUser($actor)->authorize('share', $share->node);

        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
        }

        return $share;
    }
}
