<?php

namespace App\Support;

use App\Models\User;

class Registration
{
    /**
     * Self-registration is open on a fresh install, so the first person can create the admin
     * account, and after that only when SHED_REGISTRATION=true. Otherwise admins add users.
     */
    public static function open(): bool
    {
        return config('shed.registration') || ! User::query()->exists();
    }
}
