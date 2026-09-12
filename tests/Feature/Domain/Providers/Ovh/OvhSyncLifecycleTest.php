<?php

namespace Tests\Feature\Domain\Providers\Ovh;

use App\Domain\Costs\Models\CostItem;
use App\Domain\History\Models\CostSnapshot;
use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Actions\UpdateProviderCredentials;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use App\Domain\Sync\Actions\RequestSync;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\FakeOvhApi;

beforeEach(function () {
    $this->freezeTime();
    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(OvhProviderAdapter::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * The fixture payload for one complete run: a service listing plus one
 * metadata capture per listed service (and the /me identity).
 */
function ovhRunPayload(string $listing = 'service-run-a.json'): array
{
    $names = ovhFixture($listing);
    $responses = [
        '/me' => ovhFixture('me.json'),
        '/service' => $names,
        '/order/catalog/formatted/vps' => ovhFixture('catalog/vps-eu.json'),
        '/order/catalog/formatted/cloud' => ovhFixture('catalog/cloud-eu.json'),
        '/order/catalog/formatted/domain' => ovhFixture('catalog/domain-eu.json'),
        '/order/catalog/formatted/ip' => ovhFixture('catalog/ip-eu.json'),
    ];

    foreach ($names as $name) {
        $responses['/service/'.$name] = ovhFixture('service/'.$name.'.json');
    }

    return $responses;
}

/**
 * Register the real OVH adapter against a mutable fake client and
 * create an enabled account with credentials to match. The returned
 * fake stays re-scriptable between runs.
 */
function ovhStack(): FakeOvhApi
{
    $api = new FakeOvhApi(ovhRunPayload());

    app()->bind(BuildOvhApi::class, fn (): BuildOvhApi => new class($api) extends BuildOvhApi
    {
        public function __construct(private readonly OvhApi $api) {}

        public function build(array $payload): OvhApi
        {
            return $this->api;
        }
    });

    app(AdapterRegistry::class)->register('ovh', app(OvhProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id, 'payload' => ovhPayload()]);

    return $api;
}

function ovhAccount(): ProviderAccount
{
    return ProviderAccount::query()->where('provider_key', 'ovh')->sole();
}

function queueOvhRun(ProviderAccount $account): SyncRun
{
    return SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
        'started_at' => null,
        'finished_at' => null,
    ]);
}

function ovhSync(ProviderAccount $account): SyncRun
{
    return app(SyncOrchestrator::class)->run(queueOvhRun($account));
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('creates every discovered service on the first complete run', function () {
    ovhStack();
    $account = ovhAccount();

    $run = ovhSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['inventory'])->toBe(['seen' => 4, 'created' => 4, 'updated' => 0])
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(4);

    $vps = Service::query()->where('external_id', 'vps-synthetic-01')->sole();

    expect($vps->category)->toBe('compute')
        ->and($vps->provider_type)->toBe('vps')
        ->and($vps->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($vps->first_seen_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($vps->last_seen_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('is idempotent: re-running the same inventory changes nothing', function () {
    ovhStack();
    $account = ovhAccount();

    ovhSync($account);
    $before = Service::query()
        ->where('provider_account_id', $account->id)
        ->pluck('updated_at', 'external_id')
        ->map(fn (CarbonImmutable $at): string => $at->toDateTimeString());

    $second = ovhSync($account);

    expect($second->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($second->counts['inventory'])->toBe(['seen' => 4, 'created' => 0, 'updated' => 0])
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(4);

    $after = Service::query()
        ->where('provider_account_id', $account->id)
        ->pluck('updated_at', 'external_id')
        ->map(fn (CarbonImmutable $at): string => $at->toDateTimeString());

    expect($after)->toEqual($before);
});

it('walks a service omitted from complete runs to missing and then inactive', function () {
    config(['sync.inactive_after_complete_runs' => 3]);

    $api = ovhStack();
    $account = ovhAccount();

    ovhSync($account);
    $ip = Service::query()->where('external_id', 'ip-synthetic-01')->sole();
    expect($ip->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($ip->missing_complete_runs)->toBe(0);

    $this->travel(1)->day();
    $api->responses = ovhRunPayload('service-run-b.json');
    ovhSync($account);
    expect($ip->refresh()->lifecycle_state)->toBe(ServiceLifecycle::Missing)
        ->and($ip->missing_complete_runs)->toBe(1);

    $this->travel(1)->day();
    ovhSync($account);
    expect($ip->refresh()->lifecycle_state)->toBe(ServiceLifecycle::Missing)
        ->and($ip->missing_complete_runs)->toBe(2);

    $this->travel(1)->day();
    $third = ovhSync($account);
    expect($third->status)->toBe(SyncStatus::Succeeded)
        ->and($ip->refresh()->lifecycle_state)->toBe(ServiceLifecycle::Inactive)
        ->and($ip->missing_complete_runs)->toBe(3);
});

it('never marks a service missing from a partial inventory', function () {
    $api = ovhStack();
    $account = ovhAccount();
    ovhSync($account);

    // Run B lists the cloud project but its metadata fetch fails: the
    // run is partial, so nothing absent may be treated as gone.
    $api->responses = ovhRunPayload('service-run-b.json');
    $api->throwOn('/service/cloud-project-synthetic-01', [
        new TransientProviderException('OVH API server error for [/service/cloud-project-synthetic-01] (HTTP 503).'),
    ]);

    $run = ovhSync($account);

    $ip = Service::query()->where('external_id', 'ip-synthetic-01')->sole();
    $ipCharge = CostItem::query()
        ->where('logical_charge_key', sprintf('ovh:account:%d:charge:ovh:renewal:ip-synthetic-01', $account->id))
        ->sole();

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and($ip->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($ip->missing_complete_runs)->toBe(0)
        // A partial inventory hides the IP block, so its renewal charge
        // is unreported this run — absence must not end it.
        ->and($run->counts['cost_facts']['ended'] ?? 0)->toBe(0)
        ->and($ipCharge->refresh()->valid_to)->toBeNull();
});

it('never re-validates credentials that are verified and unchanged', function () {
    $api = ovhStack();
    $account = ovhAccount();

    ovhSync($account);
    $callsAfterFirst = $api->callCount('/me');

    ovhSync($account);

    expect($api->callCount('/me'))->toBe($callsAfterFirst);
});

it('re-validates credentials after they are replaced', function () {
    $api = ovhStack();
    $account = ovhAccount();

    ovhSync($account);
    $callsBefore = $api->callCount('/me');

    UpdateProviderCredentials::class;
    app(UpdateProviderCredentials::class)->update($account, [
        'display_name' => $account->display_name,
        'endpoint' => 'ovh-eu',
        'application_key' => ovhPayload()['application_key'],
        'application_secret' => ovhPayload()['application_secret'],
        'consumer_key' => ovhPayload()['consumer_key'],
    ]);

    ovhSync($account);

    expect($api->callCount('/me'))->toBe($callsBefore + 1);
});

it('clears a verified credential when the provider rejects it mid-run', function () {
    $api = ovhStack();
    $account = ovhAccount();

    ovhSync($account);
    $credential = $account->credentials()->latest('id')->first();
    expect($credential->refresh()->verified_at)->not->toBeNull();

    // The verified credential skips validation, so the rejection must
    // surface mid-run, during the inventory fetch.
    $api->throwOn('/service', [
        new InvalidCredentialsException('OVH rejected the credentials for [/service] (HTTP 401).'),
    ]);

    $run = ovhSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Failed)
        ->and($credential->refresh()->verified_at)->toBeNull();
});

it('scopes services and charges to their own account', function () {
    $api = ovhStack();
    $accountA = ovhAccount();

    // A second account with the same external service names.
    $accountB = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    ProviderCredential::factory()->create([
        'provider_account_id' => $accountB->id,
        'payload' => ovhPayload(),
    ]);

    ovhSync($accountA);
    ovhSync($accountB);

    foreach ([$accountA, $accountB] as $account) {
        expect(Service::query()->where('provider_account_id', $account->id)->count())->toBe(4)
            ->and(CostItem::query()
                ->where('logical_charge_key', 'like', "ovh:account:{$account->id}:charge:%")
                ->count())->toBe(4);
    }

    // Run B omits the IP block for account A only.
    $api->responses = ovhRunPayload('service-run-b.json');
    $this->travel(1)->day();
    ovhSync($accountA);

    $chargeA = CostItem::query()
        ->where('logical_charge_key', sprintf('ovh:account:%d:charge:ovh:renewal:ip-synthetic-01', $accountA->id))
        ->sole();
    $chargeB = CostItem::query()
        ->where('logical_charge_key', sprintf('ovh:account:%d:charge:ovh:renewal:ip-synthetic-01', $accountB->id))
        ->sole();

    expect($chargeA->refresh()->valid_to)->not->toBeNull()
        ->and($chargeB->refresh()->valid_to)->toBeNull();
});

it('keeps last good data when the listing fails and the capability goes stale', function () {
    $api = ovhStack();
    $account = ovhAccount();
    ovhSync($account);

    $api->responses = ['/me' => ovhFixture('me.json')];
    $api->throwOn('/service', array_fill(
        0,
        (int) config('sync.retry.max_attempts'),
        new TransientProviderException('OVH rate limit reached for [/service] (HTTP 429).'),
    ));

    $run = ovhSync($account);

    $ip = Service::query()->where('external_id', 'ip-synthetic-01')->sole();

    expect($run->refresh()->status)->toBe(SyncStatus::Failed)
        ->and($ip->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(4);

    $inventory = ProviderCapabilityState::query()
        ->where('provider_account_id', $account->id)
        ->where('capability_key', ProviderCapability::Inventory->value)
        ->sole();

    expect($inventory->healthy)->toBeFalse()
        ->and($inventory->last_attempt_at)->not->toBeNull();
});

it('marks a run failed when the sync job cannot be dispatched', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    Bus::shouldReceive('dispatch')
        ->andThrow(new \RuntimeException('queue connection refused'));

    $run = app(RequestSync::class)->request($account, 'manual');

    // The run is marked failed (not left queued), the warning is
    // recorded, and the next request is no longer blocked.
    $second = app(RequestSync::class)->request($account, 'manual');

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(SyncStatus::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->summary['warnings'][0])->toContain('could not queue the sync job')
        ->and($second)->not->toBeNull();
});

it('captures a snapshot after a partial sync but writes nothing when a run fails', function () {
    $api = ovhStack();
    $account = ovhAccount();

    // Partial inventory: cloud metadata fails, so the run is partial and
    // a snapshot is still captured (the inputs moved).
    $api->responses = ovhRunPayload('service-run-a.json');
    $api->throwOn('/service/cloud-project-synthetic-01', [
        new TransientProviderException('OVH API server error for [/service/cloud-project-synthetic-01] (HTTP 503).'),
    ]);

    ovhSync($account);
    $afterPartial = CostSnapshot::query()->count();
    expect($afterPartial)->toBe(1);

    // A failed run changes nothing and writes nothing.
    $api->responses = ['/me' => ovhFixture('me.json')];
    $api->throwOn('/service', array_fill(
        0,
        (int) config('sync.retry.max_attempts'),
        new TransientProviderException('OVH rate limit reached for [/service] (HTTP 429).'),
    ));

    $failed = ovhSync($account);

    expect($failed->refresh()->status)->toBe(SyncStatus::Failed)
        ->and(CostSnapshot::query()->count())->toBe(1);
});

it('records inventory and renewal quotes as the supported capabilities', function () {
    ovhStack();
    $account = ovhAccount();

    $run = ovhSync($account);

    $states = ProviderCapabilityState::query()
        ->where('provider_account_id', $account->id)
        ->pluck('supported', 'capability_key');

    expect($run->refresh()->counts)->toHaveKey('inventory')
        ->and($run->counts['cost_facts'])->toBe(['seen' => 4, 'created' => 4, 'superseded' => 0, 'updated' => 0, 'renewals' => 1, 'ended' => 0])
        ->and($states[ProviderCapability::Inventory->value])->toBeTrue()
        ->and($states[ProviderCapability::RenewalQuotes->value])->toBeTrue()
        ->and($states[ProviderCapability::Subscriptions->value])->toBeFalse()
        ->and($states[ProviderCapability::Usage->value])->toBeFalse()
        ->and($states[ProviderCapability::Invoices->value])->toBeFalse();

    $inventory = ProviderCapabilityState::query()
        ->where('provider_account_id', $account->id)
        ->where('capability_key', ProviderCapability::Inventory->value)
        ->sole();

    expect($inventory->healthy)->toBeTrue()
        ->and($inventory->last_success_at)->not->toBeNull()
        ->and($inventory->last_observed_at)->not->toBeNull();
});
