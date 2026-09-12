<?php

use App\Domain\History\Models\CostSnapshot;
use App\Domain\Ops\Jobs\HeartbeatJob;
use App\Domain\Ops\Ops;
use App\Domain\Ops\OpsCheck;
use App\Domain\Ops\OpsHealth;
use App\Domain\Providers\Models\ProviderCredential;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    CarbonImmutable::setTestNow('2026-09-10 12:00:00');

    // Baseline: the administrator exists since three days, so the
    // pipeline is past the install grace and liveness is armed.
    $this->user = User::factory()->create(['created_at' => now()->subDays(3)]);
    $this->actingAs($this->user);
});

function beat(string $key, CarbonImmutable $at): void
{
    Cache::put($key, $at->toIso8601String());
}

function markInstalled(CarbonImmutable $at): void
{
    // Single-administrator app: rewinding the admin's creation rewinds
    // the install anchor.
    User::query()->update(['created_at' => $at]);
}

function checkByName(array $checks, string $name): OpsCheck
{
    return collect($checks)->firstOrFail(fn (OpsCheck $check) => $check->name === $name);
}

it('passes every check on a healthy pipeline', function () {
    beat(Ops::SCHEDULER_HEARTBEAT, now());
    beat(Ops::QUEUE_HEARTBEAT, now());

    $checks = app(OpsHealth::class)->checks();

    expect($checks)->each(fn ($check) => $check->ok->toBeTrue());
});

it('graces a fresh install without any heartbeat', function () {
    // Installed two minutes ago: the pipeline has not had a chance to
    // beat yet.
    User::query()->update(['created_at' => now()->subMinutes(2)]);

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
    beat(Ops::SCHEDULER_HEARTBEAT, now()->subMinutes(30));
    beat(Ops::QUEUE_HEARTBEAT, now());

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'scheduler')->ok)->toBeFalse()
        ->and(checkByName($checks, 'scheduler')->detail)->toContain('30 minute(s)')
        ->and(checkByName($checks, 'queue')->ok)->toBeTrue();
});

it('reads a heartbeat exactly at the staleness threshold as alive', function () {
    beat(Ops::SCHEDULER_HEARTBEAT, now()->subMinutes(5));

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'scheduler')->ok)->toBeTrue();
});

it('reads a heartbeat past the staleness threshold as dead', function () {
    beat(Ops::SCHEDULER_HEARTBEAT, now()->subMinutes(6));

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'scheduler')->ok)->toBeFalse();
});

it('stops gracing once the install passes the grace window', function () {
    User::query()->update(['created_at' => now()->subMinutes(11)]);

    $checks = app(OpsHealth::class)->checks();

    expect(checkByName($checks, 'scheduler')->ok)->toBeFalse();
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
        ->and(checkByName($checks, 'credentials')->detail)->toContain('cannot be read: The payload is invalid.');
});

it('warns when the latest snapshot lags more than two days', function () {
    CostSnapshot::factory()->create(['snapshot_date' => now()->subDays(6)]);

    $check = checkByName(app(OpsHealth::class)->checks(), 'snapshots');

    expect($check->ok)->toBeFalse()
        ->and($check->critical)->toBeFalse()
        ->and($check->detail)->toContain(now()->subDays(6)->toDateString());
});

it('treats a missing snapshot as fresh, never an error', function () {
    $check = checkByName(app(OpsHealth::class)->checks(), 'snapshots');

    expect($check->ok)->toBeTrue()
        ->and($check->critical)->toBeFalse()
        ->and($check->detail)->toBe('none captured yet');
});

it('runs through lafiel:ops with a passing pipeline', function () {
    beat(Ops::SCHEDULER_HEARTBEAT, now());
    beat(Ops::QUEUE_HEARTBEAT, now());

    $this->artisan('lafiel:ops')->assertSuccessful();
});

it('fails lafiel:ops when the scheduler is dead', function () {
    markInstalled(now()->subHours(6));

    $this->artisan('lafiel:ops')->assertFailed();
});

it('keeps warning-only failures from failing lafiel:ops and /up', function () {
    beat(Ops::SCHEDULER_HEARTBEAT, now());
    beat(Ops::QUEUE_HEARTBEAT, now());

    // A six-day-old snapshot is a warning, never an outage.
    CostSnapshot::factory()->create(['snapshot_date' => now()->subDays(6)]);

    $this->artisan('lafiel:ops')->assertSuccessful();
    $this->get('/up')->assertOk();
});

it('writes the scheduler heartbeat under the key OpsHealth reads', function () {
    $this->artisan('lafiel:heartbeat')->assertSuccessful();

    expect(cache()->get(Ops::SCHEDULER_HEARTBEAT))->not->toBeNull()
        ->and(checkByName(app(OpsHealth::class)->checks(), 'scheduler')->ok)->toBeTrue();
});

it('writes the queue heartbeat from inside the executed job', function () {
    (new HeartbeatJob)->handle();

    expect(cache()->get(Ops::QUEUE_HEARTBEAT))->not->toBeNull()
        ->and(checkByName(app(OpsHealth::class)->checks(), 'queue')->ok)->toBeTrue();
});
