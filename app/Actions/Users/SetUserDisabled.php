<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SetUserDisabled
{
    /**
     * A disabled user cannot sign in and is signed out on their next request; their files and
     * share links stay as they are. Admins cannot disable themselves.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $user, bool $disabled): User
    {
        Gate::forUser($actor)->authorize('admin');

        if ($disabled && $user->is($actor)) {
            throw ValidationException::withMessages(['user' => __('You cannot disable your own account.')]);
        }

        $user->forceFill(['disabled_at' => $disabled ? ($user->disabled_at ?? now()) : null])->save();

        if ($disabled) {
            // Drop the sessions of the database session driver right away.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return $user;
    }
}
