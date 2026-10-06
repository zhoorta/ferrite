<?php

namespace App\Actions\Sharing;

use App\Enums\Permission;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShareWithUser
{
    /**
     * Give another user access by e-mail address, or change what they already have.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Node $node, string $email, Permission $permission): User
    {
        Gate::forUser($actor)->authorize('share', $node);

        $recipient = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        if ($recipient === null) {
            throw ValidationException::withMessages(['email' => __('No user with that e-mail address.')]);
        }

        if ($recipient->is($actor) || $recipient->id === $node->owner_id) {
            throw ValidationException::withMessages(['email' => __('That is you: you already own this.')]);
        }

        $node->sharedWith()->syncWithoutDetaching([$recipient->id => ['permission' => $permission->value]]);

        return $recipient;
    }
}
