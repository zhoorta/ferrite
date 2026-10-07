<?php

namespace App\Support;

use App\Models\User;

class Registration
{
    /**
     * Self-registration is open on a fresh install, so the first person can create the admin
     * account, and after that only when FERRITE_REGISTRATION=true. Otherwise admins add users.
     */
    public static function open(): bool
    {
        return ! Demo::enabled() && (config('ferrite.registration') || ! User::query()->exists());
    }

    /**
     * A fresh install with no accounts yet: visitors are sent to create the admin account instead
     * of to a sign-in form nobody can use. Never on a demo instance, which has no admin by design.
     */
    public static function needsSetup(): bool
    {
        return ! Demo::enabled() && ! User::query()->exists();
    }
}
