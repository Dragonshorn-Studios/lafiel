<?php

namespace App\Actions\Setup\Commands;

use App\Actions\Setup\ResetAdministratorPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ResetAdministratorPasswordCommand extends Command
{
    protected $signature = 'lafiel:reset-admin-password
        {--password= : The new password for the administrator}';

    protected $description = 'Reset the administrator user password';

    public function handle(ResetAdministratorPassword $resetPassword): int
    {
        $user = User::query()->first();

        if ($user === null) {
            $this->error('No administrator account exists yet. Please complete setup first.');

            return self::FAILURE;
        }

        $passwordOption = $this->option('password');

        if ($passwordOption !== null) {
            $password = (string) $passwordOption;
            $passwordConfirmation = (string) $passwordOption;
        } elseif ($this->input->isInteractive()) {
            $password = (string) $this->secret('New password');
            $passwordConfirmation = (string) $this->secret('Confirm new password');

            if ($password !== $passwordConfirmation) {
                $this->error('The password confirmation does not match.');

                return self::FAILURE;
            }
        } else {
            $this->error('The --password option is required in non-interactive mode.');

            return self::FAILURE;
        }

        try {
            $updatedUser = $resetPassword->reset([
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ]);

            if ($updatedUser === null) {
                $this->error('No administrator account exists yet. Please complete setup first.');

                return self::FAILURE;
            }

            $this->info("Password reset successfully for administrator [{$updatedUser->email}].");

            return self::SUCCESS;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $fieldErrors) {
                foreach ($fieldErrors as $error) {
                    $this->error($error);
                }
            }

            return self::FAILURE;
        }
    }
}
