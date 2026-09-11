<?php

use App\Actions\Setup\CreateAdministrator;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

test('setup screen is shown while no administrator exists', function () {
    $response = $this->get(route('setup'));

    $response->assertOk();
    $response->assertSee(route('setup.store'), false);
    $response->assertSee(__('Create administrator'));
});

test('setup screen redirects to login once the administrator exists', function () {
    User::factory()->create();

    $response = $this->get(route('setup'));

    $response->assertRedirect(route('login'));
});

test('guests are sent to setup from guest-reachable pages while not installed', function () {
    $response = $this->get(route('login'));

    $response->assertRedirect(route('setup'));
});

test('administrator can be created exactly once through the setup screen', function () {
    $response = $this->post(route('setup.store'), [
        'name' => 'Imperial Administrator',
        'email' => 'admin@lafiel.local',
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
    ]);

    $response->assertRedirect(route('overview', absolute: false));

    $user = User::query()->sole();

    expect($user->name)->toEqual('Imperial Administrator');
    expect($user->email)->toEqual('admin@lafiel.local');
    expect($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('setup validates administrator input', function () {
    $response = $this->from(route('setup'))->post(route('setup.store'), [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'other',
    ]);

    $response->assertRedirect(route('setup'));
    $response->assertSessionHasErrors(['name', 'email', 'password']);

    expect(User::query()->count())->toEqual(0);
});

test('setup redirects an already installed application instead of creating a second administrator', function () {
    $existing = User::factory()->create();

    $response = $this->post(route('setup.store'), [
        'name' => 'Second Administrator',
        'email' => 'second@lafiel.local',
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
    ]);

    $response->assertRedirect(route('login'));

    expect(User::query()->sole()->is($existing))->toBeTrue();
    $this->assertGuest();
});

test('setup refuses to create while another setup attempt holds the lock', function () {
    $lock = Cache::lock('setup:administrator', 10);
    $lock->acquire();

    $user = app(CreateAdministrator::class)->create([
        'name' => 'Imperial Administrator',
        'email' => 'admin@lafiel.local',
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
    ]);

    $lock->release();

    expect($user)->toBeNull();
    expect(User::query()->count())->toEqual(0);
});

test('setup action is idempotent when the administrator appeared before creation', function () {
    $existing = User::factory()->create();

    $user = app(CreateAdministrator::class)->create([
        'name' => 'Second Administrator',
        'email' => 'second@lafiel.local',
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
    ]);

    expect($user)->toBeNull();
    expect(User::query()->sole()->is($existing))->toBeTrue();
});

test('the database rejects a second user even when the application is bypassed', function () {
    User::factory()->create();

    // On Postgres the violating statement aborts the surrounding
    // transaction, so nothing else can be queried in this test after it.
    expect(fn () => User::factory()->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('public registration, password reset, and email verification stay disabled', function () {
    User::factory()->create();

    $this->get('/register')->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
    $this->get('/reset-password/token')->assertNotFound();
    $this->get('/email/verify')->assertNotFound();

    $this->post('/register', [
        'name' => 'Sneaky Administrator',
        'email' => 'sneaky@lafiel.local',
        'password' => 'super-secret-password',
        'password_confirmation' => 'super-secret-password',
    ])->assertNotFound();

    expect(User::query()->count())->toEqual(1);
});

test('mutations require an authenticated session', function () {
    User::factory()->create();

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('mutations run behind request-forgery protection', function () {
    $web = app(Kernel::class)->getMiddlewareGroups()['web'];

    expect($web)->toContain(PreventRequestForgery::class);
});

test('the health check stays reachable while not installed', function () {
    $this->get('/up')->assertOk();
});
