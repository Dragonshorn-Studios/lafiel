<?php

namespace Tests\Feature\Domain\Providers\Hetzner\Cloud;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Costs\Projection\ProjectionFormatter;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Hetzner\Cloud\BuildHetznerCloudApi;
use App\Domain\Providers\Hetzner\Cloud\HetznerCloudApi;
use App\Domain\Providers\Hetzner\Cloud\HetznerCloudProviderAdapter;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use App\Models\User;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeHetznerCloudApi;

beforeEach(function () {
    $this->freezeTime();
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(HetznerCloudProviderAdapter::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function hetznerRunPayload(): array
{
    return [
        '/servers' => hetznerCloudFixture('servers.json'),
        '/load_balancers' => hetznerCloudFixture('load-balancers.json'),
        '/primary_ips' => hetznerCloudFixture('primary-ips.json'),
        '/floating_ips' => hetznerCloudFixture('floating-ips.json'),
        '/volumes' => hetznerCloudFixture('volumes.json'),
        '/pricing' => hetznerCloudFixture('pricing.json'),
    ];
}

/**
 * Register the real Hetzner Cloud adapter against a mutable fake
 * client and create an enabled account with credentials to match.
 */
function hetznerStack(): FakeHetznerCloudApi
{
    $api = new FakeHetznerCloudApi(hetznerRunPayload());

    app()->bind(BuildHetznerCloudApi::class, fn (): BuildHetznerCloudApi => new class($api) extends BuildHetznerCloudApi
    {
        public function __construct(private readonly HetznerCloudApi $api) {}

        public function build(array $payload): HetznerCloudApi
        {
            return $this->api;
        }
    });

    app(AdapterRegistry::class)->register('hetzner-cloud', app(HetznerCloudProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'hetzner-cloud', 'display_name' => 'HC synthetic']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id, 'payload' => hetznerCloudPayload()]);

    return $api;
}

function hetznerLifecycleAccount(): ProviderAccount
{
    return ProviderAccount::query()->where('provider_key', 'hetzner-cloud')->sole();
}

function hetznerSync(ProviderAccount $account): SyncRun
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

function hetznerCharge(ProviderAccount $account, int $resourceId): CostItem
{
    return CostItem::query()
        ->where('logical_charge_key', sprintf('hetzner-cloud:account:%d:charge:hetzner:cloud:resource:%d', $account->id, $resourceId))
        ->sole();
}

function hetznerVersion(ProviderAccount $account, int $resourceId, int $index): CostItem
{
    return CostItem::query()
        ->where('logical_charge_key', sprintf('hetzner-cloud:account:%d:charge:hetzner:cloud:resource:%d', $account->id, $resourceId))
        ->orderBy('id')
        ->get()
        ->get($index);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('syncs all billable sections with catalog estimates', function () {
    hetznerStack();
    $account = hetznerLifecycleAccount();

    $run = hetznerSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['inventory'])->toBe(['seen' => 6, 'created' => 6, 'updated' => 0])
        ->and($run->counts['cost_facts']['seen'])->toBe(6)
        ->and($run->counts['cost_facts']['created'])->toBe(6);

    // The catalog estimate: net, exclusive VAT, monthly.
    $server = hetznerCharge($account, 4200001);

    expect($server->amount_minor)->toBe(499)
        ->and($server->currency)->toBe('EUR')
        ->and($server->evidence_state->value)->toBe('estimate')
        ->and($server->tax_basis->value)->toBe('exclusive')
        ->and($server->notes)->toBe('Hetzner Cloud catalog (net of VAT 19.000000)');

    // The cx32 @ nbg1 join matches nothing — honestly unknown.
    $unknown = hetznerCharge($account, 4200002);

    expect($unknown->amount_state->value)->toBe('unknown')
        ->and($run->summary['warnings'])->toContain('1 resource(s) have no catalog price; their charges stay unknown.');
});

it('prices a volume exactly as per-GB times size, rounded once', function () {
    hetznerStack();
    $account = hetznerLifecycleAccount();

    hetznerSync($account);

    // 0.476 EUR/GB × 42 GB = 19.992 EUR — rounded once, half-even, to 19.99.
    expect(hetznerCharge($account, 4600001)->amount_minor)->toBe(1999);
});

it('falls back to the IP as the display name for nameless addresses', function () {
    hetznerStack();
    $account = hetznerLifecycleAccount();

    hetznerSync($account);

    $ip = Service::query()
        ->where('provider_account_id', $account->id)
        ->where('external_id', '4400001')
        ->sole();

    expect($ip->name)->toBe('116.203.0.10')
        ->and($ip->provider_type)->toBe('ipv4')
        ->and($ip->category)->toBe('network');
});

it('rewrites nothing when the catalog changes: the old version keeps its price', function () {
    $api = hetznerStack();
    $account = hetznerLifecycleAccount();

    hetznerSync($account);
    $this->travel(1)->day();

    $pricing = hetznerCloudFixture('pricing.json');
    $pricing['pricing']['server_types'][0]['price_monthly']['net'] = '5.4900000000';
    $api->responses['/pricing'] = $pricing;

    $run = hetznerSync($account);

    expect($run->refresh()->counts['cost_facts']['superseded'])->toBe(1)
        ->and(hetznerVersion($account, 4200001, 0)->amount_minor)->toBe(499)
        ->and(hetznerVersion($account, 4200001, 1)->amount_minor)->toBe(549);
});

it('ends the estimate of a resource that disappears on a complete run', function () {
    $api = hetznerStack();
    $account = hetznerLifecycleAccount();

    hetznerSync($account);

    $payload = hetznerRunPayload();
    $payload['/servers']['servers'] = array_values(array_filter(
        $payload['/servers']['servers'],
        fn (array $server): bool => $server['id'] !== 4200002,
    ));
    $payload['/servers']['meta']['pagination']['total_entries'] = count($payload['/servers']['servers']);
    $api->responses = $payload;
    $this->travel(1)->day();

    $run = hetznerSync($account);

    expect($run->refresh()->counts['cost_facts']['ended'])->toBe(1)
        ->and(hetznerCharge($account, 4200002)->refresh()->valid_to)->not->toBeNull()
        ->and(hetznerCharge($account, 4200001)->refresh()->valid_to)->toBeNull();
});

it('keeps the estimates out of the hard total with the tilde prefix', function () {
    hetznerStack();
    $account = hetznerLifecycleAccount();

    hetznerSync($account);

    $result = app(CostProjector::class)->project(now());
    $formatted = app(ProjectionFormatter::class)->monthly($result);

    expect($result->estimateCount)->toBe(5)
        ->and($formatted)->toStartWith('~');
});

it('degrades to a partial run when pricing fails but keeps the inventory', function () {
    $api = hetznerStack();
    $account = hetznerLifecycleAccount();

    unset($api->responses['/pricing']);
    $api->throwOn('/pricing', [
        new TransientProviderException('Hetzner Cloud API server error for [/pricing] (HTTP 503).'),
    ]);

    $run = hetznerSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(6)
        // Pricing is down: every charge exists with an unknown price.
        ->and(CostItem::query()->count())->toBe(6)
        ->and(CostItem::query()->whereNotNull('amount_minor')->count())->toBe(0);
});
