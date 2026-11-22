<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may create an account (NEWSSTAND_REGISTRATION):
 *   open      anyone can sign up
 *   closed    only the very first account (so a fresh install can create its admin)
 *   disabled  nobody; create users with `php artisan newsstand:user`
 */
class Registration
{
    public const MODES = ['open', 'closed', 'disabled'];

    public static function isOpen(): bool
    {
        return match (config('newsstand.registration')) {
            'open' => true,
            'disabled' => false,
            default => ! User::query()->exists(),
        };
    }
}
