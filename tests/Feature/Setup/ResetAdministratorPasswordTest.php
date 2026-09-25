<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('reset admin password command fails when no administrator exists', function () {
    $this->artisan('lafiel:reset-admin-password', ['--password' => 'new-secure-password'])
        ->expectsOutput('No administrator account exists yet. Please complete setup first.')
        ->assertFailed();
});

test('reset admin password command resets password with option', function () {
    $user = User::factory()->create([
        'email' => 'admin@lafiel.local',
        'password' => 'old-password',
    ]);

    $this->artisan('lafiel:reset-admin-password', ['--password' => 'new-secure-password'])
        ->expectsOutput('Password reset successfully for administrator [admin@lafiel.local].')
        ->assertSuccessful();

    expect(Hash::check('new-secure-password', $user->refresh()->password))->toBeTrue();
});

test('reset admin password command prompts for password interactively', function () {
    $user = User::factory()->create([
        'email' => 'admin@lafiel.local',
        'password' => 'old-password',
    ]);

    $this->artisan('lafiel:reset-admin-password')
        ->expectsQuestion('New password', 'interactive-new-password')
        ->expectsQuestion('Confirm new password', 'interactive-new-password')
        ->expectsOutput('Password reset successfully for administrator [admin@lafiel.local].')
        ->assertSuccessful();

    expect(Hash::check('interactive-new-password', $user->refresh()->password))->toBeTrue();
});

test('reset admin password command fails when interactive confirmation mismatches', function () {
    User::factory()->create([
        'email' => 'admin@lafiel.local',
        'password' => 'old-password',
    ]);

    $this->artisan('lafiel:reset-admin-password')
        ->expectsQuestion('New password', 'interactive-new-password')
        ->expectsQuestion('Confirm new password', 'mismatched-password')
        ->expectsOutput('The password confirmation does not match.')
        ->assertFailed();
});

test('reset admin password command fails in non-interactive mode without password option', function () {
    User::factory()->create();

    $this->artisan('lafiel:reset-admin-password', ['--no-interaction' => true])
        ->expectsOutput('The --password option is required in non-interactive mode.')
        ->assertFailed();
});

test('reset admin password command fails validation for invalid password', function () {
    User::factory()->create();

    $this->artisan('lafiel:reset-admin-password', ['--password' => ''])
        ->assertFailed();
});
