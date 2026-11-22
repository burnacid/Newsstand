<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Who may create an account. Set by admins under Admin → Settings, falling back
 * to NEWSSTAND_REGISTRATION:
 *   open      anyone can sign up
 *   closed    only the very first account (so a fresh install can create its admin)
 *   disabled  nobody; admins create users in the panel or with `php artisan newsstand:user`
 */
class Registration
{
    public const MODES = ['open', 'closed', 'disabled'];

    public static function mode(): string
    {
        $mode = Setting::get('registration', config('newsstand.registration'));

        return in_array($mode, self::MODES, true) ? $mode : 'closed';
    }

    public static function isOpen(): bool
    {
        return match (self::mode()) {
            'open' => true,
            'disabled' => false,
            default => ! User::query()->exists(),
        };
    }
}
