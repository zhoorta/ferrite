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
}
