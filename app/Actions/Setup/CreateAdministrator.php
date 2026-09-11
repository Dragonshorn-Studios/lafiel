<?php

namespace App\Actions\Setup;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateAdministrator
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create the single local administrator. Returns null when no
     * administrator was created: either one already exists, or another setup
     * attempt holds the setup lock. Both resolve to "the application is or
     * will be installed", and the database singleton constraint is the
     * final backstop against a lost race.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): ?User
    {
        $validated = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $result = Cache::lock('setup:administrator', 10)->get(
            callback: fn (): ?User => $this->createValidated($validated),
        );

        return $result instanceof User ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createValidated(array $validated): ?User
    {
        if (User::query()->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($validated): User {
                $user = new User;
                $user->name = $validated['name'];
                $user->email = $validated['email'];
                $user->password = $validated['password'];
                $user->email_verified_at = now();
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
