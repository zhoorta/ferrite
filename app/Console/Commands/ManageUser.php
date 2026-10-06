<?php

namespace App\Console\Commands;

use App\Actions\Users\SaveUser;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;

#[Signature('shed:user {email : E-mail address of the user}
    {--name= : Display name (new users; defaults to the part before the @)}
    {--password= : Password (prefer the prompt: this ends up in your shell history)}
    {--admin : Make the user an admin}
    {--user : Make the user an ordinary user, not an admin}
    {--quota= : Quota in GB, or "unlimited"}
    {--enable : Re-enable a disabled account}')]
#[Description('Create a user, or change an existing one (password, role, quota). For setup and recovery without the web UI')]
class ManageUser extends Command
{
    public function handle(SaveUser $save): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if ($this->option('admin') && $this->option('user')) {
            $this->error('Use either --admin or --user, not both.');

            return self::FAILURE;
        }

        $newPassword = $this->option('password');

        if ($newPassword === null && ($user === null || ($this->input->isInteractive() && confirm('Set a new password?', false)))) {
            if (! $this->input->isInteractive()) {
                $this->error('Pass --password, or run interactively to be prompted for one.');

                return self::FAILURE;
            }

            $newPassword = password('Password', required: true);

            if (password('Confirm password', required: true) !== $newPassword) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        $role = match (true) {
            (bool) $this->option('admin') => UserRole::Admin,
            (bool) $this->option('user') => UserRole::User,
            default => $user?->role ?? UserRole::User,
        };

        $quota = $this->option('quota');
        $quotaGb = match (true) {
            $quota === null => $user === null || $user->quota_bytes === null ? '' : (string) ($user->quota_bytes / 1024 ** 3),
            strtolower((string) $quota) === 'unlimited' => '',
            default => (string) $quota,
        };

        try {
            $saved = $save->save($user, [
                'name' => $this->option('name') ?? $user?->name ?? strstr($email, '@', true),
                'email' => $user?->email ?? $email,
                'password' => $newPassword,
                'role' => $role->value,
                'quota_gb' => $quotaGb,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        if ($this->option('enable') && $saved->isDisabled()) {
            $saved->forceFill(['disabled_at' => null])->save();
        }

        $this->info(($user === null ? 'Created' : 'Updated')." {$saved->email} ({$saved->role->value}, quota ".($saved->quota_bytes === null ? 'unlimited' : round($saved->quota_bytes / 1024 ** 3, 2).' GB').').');

        return self::SUCCESS;
    }
}
