<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SaveUser
{
    private const BYTES_PER_GB = 1024 ** 3;

    /**
     * Create a user, or update one. Accounts made by an admin are trusted, so e-mail is marked
     * verified. On edit a blank password keeps the current one and a blank quota means unlimited.
     *
     * @param  array{name?: mixed, email?: mixed, password?: mixed, role?: mixed, quota_gb?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, ?User $user, array $data): User
    {
        Gate::forUser($actor)->authorize('admin');

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user === null ? 'required' : 'nullable', 'string', Password::default()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'quota_gb' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ])->validate();

        $role = UserRole::from($validated['role']);

        if ($user !== null && $user->is($actor) && $role !== UserRole::Admin) {
            throw ValidationException::withMessages(['role' => __('You cannot remove your own admin role.')]);
        }

        $user ??= new User;
        $user->fill(['name' => $validated['name'], 'email' => $validated['email']]);
        $user->role = $role;
        $user->quota_bytes = blank($validated['quota_gb'] ?? null) ? null : (int) round((float) $validated['quota_gb'] * self::BYTES_PER_GB);

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        if (! $user->exists) {
            $user->email_verified_at = now();
        }

        $user->save();

        return $user;
    }
}
