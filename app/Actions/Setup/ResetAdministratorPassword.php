<?php

namespace App\Actions\Setup;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResetAdministratorPassword
{
    use PasswordValidationRules;

    /**
     * Validate and reset the single administrator's password.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function reset(array $input): ?User
    {
        $user = User::query()->first();

        if ($user === null) {
            return null;
        }

        $validated = Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        return $user;
    }
}
