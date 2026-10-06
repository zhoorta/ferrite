<?php

namespace App\Actions\Users;

use App\Actions\Nodes\PurgeNode;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    public function __construct(private PurgeNode $purge) {}

    /**
     * Delete a user together with all their files. Their blobs are removed too, so this cannot be
     * undone; disabling the account is the gentler option.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $user): void
    {
        Gate::forUser($actor)->authorize('admin');

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => __('You cannot delete your own account here.')]);
        }

        Node::query()->where('owner_id', $user->id)->whereNull('parent_id')->each(fn (Node $node) => $this->purge->purge($node));

        $user->delete();
    }
}
