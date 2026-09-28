<?php

namespace Tests\Feature\Domain\Providers\Mikrus;

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Models\CostItem;
use App\Domain\History\Models\CostSnapshot;
use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Mikrus\BuildMikrusApi;
use App\Domain\Providers\Mikrus\MikrusApi;
use App\Domain\Providers\Mikrus\MikrusProviderAdapter;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use App\Models\User;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Fakes\FakeMikrusApi;

beforeEach(function () {
    $this->freezeTime();
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(MikrusProviderAdapter::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Register the real mikr.us adapter against a mutable fake client and
 * create an enabled account with credentials to match.
 */
function mikrusStack(): FakeMikrusApi
{
    $api = new FakeMikrusApi(mikrusResponses());

    app()->bind(BuildMikrusApi::class, fn (): BuildMikrusApi => new class($api) extends BuildMikrusApi
    {
        public function __construct(private readonly MikrusApi $api) {}

        public function build(array $payload): MikrusApi
        {
            return $this->api;
        }
    });

    app(AdapterRegistry::class)->register('mikrus', app(MikrusProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'mikrus', 'display_name' => 'Mikr.us synthetic']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id, 'payload' => mikrusPayload()]);

    return $api;
}

function mikrusLifecycleAccount(): ProviderAccount
{
    return ProviderAccount::query()->where('provider_key', 'mikrus')->sole();
}

function mikrusSync(ProviderAccount $account): SyncRun
{
    $run = SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
        'started_at' => null,
        'finished_at' => null,
    ]);

    return app(SyncOrchestrator::class)->run($run);
}

function mikrusService(ProviderAccount $account, string $serverName): Service
{
    return Service::query()
        ->where('provider_account_id', $account->id)
        ->where('external_id', $serverName)
        ->sole();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('inventories servers and produces no cost facts at all', function () {
    mikrusStack();
    $account = mikrusLifecycleAccount();

    $run = mikrusSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['inventory'])->toBe(['seen' => 2, 'created' => 2, 'updated' => 0])
        ->and($run->counts)->not->toHaveKey('cost_facts')
        ->and(CostItem::query()->count())->toBe(0);

    $service = mikrusService($account, 'emil100');

    expect($service->name)->toBe('emil100')
        ->and($service->category)->toBe('compute')
        ->and($service->provider_type)->toBe('kvm')
        ->and($service->metadata['expiration'])->toBe(1798761600)
        ->and($service->metadata['is_pro'])->toBe(1);
});

it('is idempotent: re-running the same inventory adds nothing but refreshes metadata', function () {
    $api = mikrusStack();
    $account = mikrusLifecycleAccount();

    mikrusSync($account);

    // The provider-side expiration moves between runs.
    $responses = mikrusResponses();
    $responses['/info'] = fn (array $fields): array => ['name' => $fields['srv'], 'expire' => 1800000000];
    $api->responses = $responses;
    $this->travel(1)->day();

    $run = mikrusSync($account);

    expect($run->refresh()->counts['inventory'])->toBe(['seen' => 2, 'created' => 0, 'updated' => 2])
        ->and(mikrusService($account, 'emil100')->metadata['expiration'])->toBe(1800000000);
});

it('lets a manual cost from the library overlay the discovered server', function () {
    mikrusStack();
    $account = mikrusLifecycleAccount();

    mikrusSync($account);
    $service = mikrusService($account, 'emil100');

    app(CreateManualCost::class)->create([
        'name' => $service->name,
        'category' => 'compute',
        'amount' => '16.00',
        'currency' => 'PLN',
        'period' => 'monthly',
        'valid_from' => now()->toDateString(),
        'covers_service_id' => $service->id,
    ]);

    // Re-sync: the overlay survives, bound to the stable identity.
    $this->travel(1)->day();
    $run = mikrusSync($account);

    $overlay = CostItem::query()->where('logical_charge_key', 'like', 'manual:charge:%')->sole();

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($overlay->refresh()->is_manual_override)->toBeTrue()
        ->and($overlay->valid_to)->toBeNull()
        ->and($overlay->services->sole()->id)->toBe($service->id)
        ->and($service->costItems()->count())->toBe(1);
});

it('marks a service missing when it disappears from a complete listing', function () {
    $api = mikrusStack();
    $account = mikrusLifecycleAccount();

    mikrusSync($account);

    // Run B: emil200 is gone from a complete inventory. No cost facts
    // exist, so nothing ends — the service falls out of lifecycle
    // tracking instead, exactly as the inventory rules dictate.
    $responses = mikrusResponses();
    $responses['/serwery'] = [['name' => 'emil100', 'proto' => '4', 'ip' => '192.0.2.10', 'virtualization' => 'kvm']];
    $api->responses = $responses;
    $this->travel(1)->day();

    $run = mikrusSync($account);

    $gone = mikrusService($account, 'emil200');

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($gone->lifecycle_state)->toBe(ServiceLifecycle::Missing)
        ->and($gone->missing_complete_runs)->toBe(1);
});

it('degrades to partial when one server info fails and carries the warning into the summary', function () {
    $api = mikrusStack();
    $account = mikrusLifecycleAccount();
    $api->throwOn('/info', [
        new TransientProviderException('Mikr.us API request failed for [/info] (HTTP 500).'),
        new TransientProviderException('Mikr.us API request failed for [/info] (HTTP 500).'),
    ]);

    $run = mikrusSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and($run->summary['warnings'])->toContain('info for [emil100] failed: Mikr.us API request failed for [/info] (HTTP 500).')
        ->and(CostSnapshot::query()->whereDate('snapshot_date', today())->exists())->toBeTrue();
});

it('discards stored metadata when info fails on a later sync, loudly', function () {
    $api = mikrusStack();
    $account = mikrusLifecycleAccount();

    mikrusSync($account);

    expect(mikrusService($account, 'emil100')->metadata)->not->toBeNull();

    $api->throwOn('/info', [
        new TransientProviderException('Mikr.us API request failed for [/info] (HTTP 500).'),
        new TransientProviderException('Mikr.us API request failed for [/info] (HTTP 500).'),
    ]);
    $this->travel(1)->day();

    $run = mikrusSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and(mikrusService($account, 'emil100')->metadata)->toBeNull()
        ->and($run->summary['warnings'])->toContain('info for [emil100] failed: Mikr.us API request failed for [/info] (HTTP 500).');
});

it('connects a mikr.us account through the provider select without echoing the key', function () {
    // The beforeEach already authenticated the single administrator.
    $component = Livewire::test('pages::providers.index')
        ->set('providerKey', 'mikrus')
        ->set('displayName', 'Mikr.us Main')
        ->set('credential.api_key', mikrusPayload()['api_key'])
        ->call('connect');

    $component->assertHasNoErrors();

    $this->assertDatabaseHas('provider_accounts', [
        'provider_key' => 'mikrus',
        'display_name' => 'Mikr.us Main',
    ]);

    expect($component->html())->not->toContain(mikrusPayload()['api_key']);
});
