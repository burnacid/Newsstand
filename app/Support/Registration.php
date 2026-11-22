<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may create an account. NEWSSTAND_REGISTRATION=open lets anyone sign up;
 * "closed" only allows the very first account (so a fresh install can create
 * its admin). Add more users with `php artisan newsstand:user`.
 */
class Registration
{
    public static function isOpen(): bool
    {
        return config('newsstand.registration') === 'open' || ! User::query()->exists();
    }
}
