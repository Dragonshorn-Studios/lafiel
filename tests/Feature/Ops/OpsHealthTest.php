<?php

use App\Domain\History\Models\CostSnapshot;
use App\Domain\Ops\Ops;
use App\Domain\Ops\OpsCheck;
use App\Domain\Ops\OpsHealth;
use App\Domain\Providers\Models\ProviderCredential;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    CarbonImmutable::setTestNow('2026-09-10 12:00:00');

    $this->actingAs(User::factory()->create());
});

function beat(string $key, CarbonImmutable $at): void
{
    Cache::put($key, $at->toIso8601String());
}

function markInstalled(CarbonImmutable $at): void
{
    Cache::put(Ops::INSTALLED_AT, $at->toIso8601String());
}

function checkByName(array $checks, string $name): OpsCheck
{
    return collect($checks)->firstOrFail(fn ($check) => $check->check === $name);
}

it('passes every check on a healthy pipeline', function () {
    beat(Ops::SCHEDULER_HEARTBEAT, now());
    beat(Ops::QUEUE_HEARTBEAT, now());
    markInstalled(now()->subDays(3));

    $checks = app(OpsHealth::class)->checks();

    expect($checks)->each(fn ($check) => $check->ok->toBeTrue());
});

it('graces a fresh install without any heartbeat', function () {
    markInstalled(now()->subMinutes(2));

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'scheduler')->ok)->toBeTrue()
        ->and(checkByName($checks, 'scheduler')->detail)->toContain('fresh install')
        ->and(checkByName($checks, 'queue')->ok)->toBeTrue();
});

it('fails the heartbeats after the install grace passes without one', function () {
    markInstalled(now()->subHours(6));

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'scheduler')->ok)->toBeFalse()
        ->and(checkByName($checks, 'scheduler')->detail)->toContain('no heartbeat ever recorded')
        ->and(checkByName($checks, 'queue')->ok)->toBeFalse()
        ->and(checkByName($checks, 'queue')->detail)->toContain('no heartbeat ever recorded');
});

it('fails a stale heartbeat', function () {
    markInstalled(now()->subDays(3));
    beat(Ops::SCHEDULER_HEARTBEAT, now()->subMinutes(30));
    beat(Ops::QUEUE_HEARTBEAT, now());

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'scheduler')->ok)->toBeFalse()
        ->and(checkByName($checks, 'scheduler')->detail)->toContain('30 minute(s)')
        ->and(checkByName($checks, 'queue')->ok)->toBeTrue();
});

it('fails when stored credentials no longer decrypt', function () {
    $credential = ProviderCredential::factory()->create();

    // Simulate a rotated APP_KEY: the raw stored payload no longer
    // decrypts with the current key.
    DB::table('provider_credentials')
        ->where('id', $credential->id)
        ->update(['payload' => 'not-really-encrypted']);

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'credentials')->ok)->toBeFalse()
        ->and(checkByName($checks, 'credentials')->detail)->toContain('unreadable with the current APP_KEY');
});

it('warns when the latest snapshot lags more than two days', function () {
    CostSnapshot::factory()->create(['snapshot_date' => now()->subDays(6)]);

    $check = checkByName(app(OpsHealth::class)->checks(), 'snapshots');

    expect($check->ok)->toBeFalse()
        ->and($check->critical)->toBeFalse()
        ->and($check->detail)->toContain(now()->subDays(6)->toDateString());
});

it('treats a missing snapshot as a warning, never an error', function () {
    $check = checkByName(app(OpsHealth::class)->checks(), 'snapshots');

    expect($check->ok)->toBeTrue()
        ->and($check->critical)->toBeFalse()
        ->and($check->detail)->toBe('none captured yet');
});

it('runs through lafiel:ops with a passing pipeline', function () {
    beat(Ops::SCHEDULER_HEARTBEAT, now());
    beat(Ops::QUEUE_HEARTBEAT, now());
    markInstalled(now()->subDays(3));

    $this->artisan('lafiel:ops')->assertSuccessful();
});

it('renders the branded error pages', function () {
    $this->get('/definitely-not-a-route')
        ->assertNotFound()
        ->assertSee('404');

    $this->get('/definitely-not-a-route', ['Accept' => 'text/html'])
        ->assertSee('Back to the fleet ledger');
});

it('fails lafiel:ops when the scheduler is dead', function () {
    markInstalled(now()->subHours(6));

    $this->artisan('lafiel:ops')->assertFailed();
});

it('fails the health endpoint once the pipeline is installed and a heartbeat goes stale', function () {
    markInstalled(now()->subDays(3));
    beat(Ops::QUEUE_HEARTBEAT, now());
    beat(Ops::SCHEDULER_HEARTBEAT, now());

    $this->get('/up')->assertOk();

    beat(Ops::SCHEDULER_HEARTBEAT, now()->subMinutes(30));

    $this->get('/up')->assertServerError();
});

it('keeps the health endpoint green during the fresh-install grace', function () {
    // No heartbeats at all: a fresh install must not flap red.
    $this->get('/up')->assertOk();
});
