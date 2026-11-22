<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Registration;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Also enforced here, not only on the form: a direct POST must not bypass it.
        abort_unless(Registration::isOpen(), 403, 'Registration is closed.');

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $first = ! User::query()->exists();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // The very first account administers the install. It's created by whoever runs the
        // server, often before mail is configured, so it doesn't need email verification.
        if ($first) {
            $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        }

        return $user;
    }
}
