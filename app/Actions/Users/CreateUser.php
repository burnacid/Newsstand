<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates an account on behalf of an admin (admin panel or `newsstand:user`).
 * Accounts are created verified; the first account on an install is always an admin.
 */
class CreateUser
{
    /** @return array{0: User, 1: string} the user and the password (generated when none was given) */
    public function handle(string $email, ?string $name = null, ?string $password = null, bool $admin = false): array
    {
        $email = Str::lower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That is not a valid email address.');
        }

        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new InvalidArgumentException("A user with {$email} already exists.");
        }

        $password = $password !== null && $password !== '' ? $password : Str::password(20);
        $first = ! User::query()->exists();

        $user = User::create([
            'name' => $name !== null && trim($name) !== '' ? trim($name) : Str::before($email, '@'),
            'email' => $email,
            'password' => $password,
        ]);

        $user->forceFill(['email_verified_at' => now(), 'is_admin' => $admin || $first])->save();

        return [$user, $password];
    }
}
