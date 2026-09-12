<?php

namespace Tests\Feature\Domain\Providers\Cloudflare;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Providers\Cloudflare\BuildCloudflareApi;
use App\Domain\Providers\Cloudflare\CloudflareApi;
use App\Domain\Providers\Cloudflare\CloudflareProviderAdapter;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeCloudflareApi;

beforeEach(function () {
    $this->freezeTime();
    Sleep::fake();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function cloudflareEnvelope(array $result): array
{
    return [
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => $result,
        'result_info' => [
            'page' => 1,
            'per_page' => 50,
            'count' => count($result),
            'total_pages' => 1,
            'total_count' => count($result),
        ],
    ];
}

function cloudflareSubscription(array $overrides = []): array
{
    return [
        'id' => 'sub-synthetic-99',
        'product' => ['id' => 'workers_paid', 'name' => 'Workers Paid'],
        'rate_plan' => [
            'id' => 'plan-workers-synthetic',
            'public_name' => 'Workers Paid',
            'currency' => 'USD',
            'components' => [
                ['name' => 'Workers Paid', 'price' => '5.00'],
            ],
        ],
        'frequency' => 'monthly',
        'state' => 'Paid',
        'current_period' => [
            'start' => '2026-09-01T00:00:00Z',
            'end' => '2026-10-01T00:00:00Z',
        ],
        'auto_renew' => true,
        ...$overrides,
    ];
}

function cloudflareAdapter(FakeCloudflareApi $api): CloudflareProviderAdapter
{
    return new CloudflareProviderAdapter(new class($api) extends BuildCloudflareApi
    {
        public function __construct(private readonly CloudflareApi $api) {}

        public function build(array $payload): CloudflareApi
        {
            return $this->api;
        }
    });
}

function cloudflareContext(): SyncContext
{
    $account = ProviderAccount::factory()->make(['provider_key' => 'cloudflare']);

    return new SyncContext($account, cloudflarePayload(), new CarbonImmutable('2026-09-12 10:00:00'));
}

function cloudflareInventory(): InventoryBatch
{
    return new InventoryBatch(
        completeness: BatchCompleteness::Complete,
        observedAt: new CarbonImmutable('2026-09-12 10:00:00'),
        sourceRef: 'cloudflare:/accounts,/zones',
        items: [
            new InventoryItem('account-synthetic-01', 'account', 'Synthetic Account', 'account'),
            new InventoryItem('zone-synthetic-01', 'dns', 'example-synthetic.com', 'zone'),
        ],
    );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('validates credentials with the token verify endpoint', function () {
    $api = new FakeCloudflareApi(['/user/tokens/verify' => cloudflareEnvelope([])]);
    $check = cloudflareAdapter($api)->validateCredentials(cloudflareContext());

    expect($check->valid)->toBeTrue()
        ->and($api->calls)->toBe(['/user/tokens/verify']);
});

it('reports rejected credentials as invalid and never retries', function () {
    Sleep::fake();

    $api = (new FakeCloudflareApi)->throwOn('/user/tokens/verify', [
        new InvalidCredentialsException('Cloudflare rejected the credentials for [/user/tokens/verify] (HTTP 401).'),
    ]);

    $check = cloudflareAdapter($api)->validateCredentials(cloudflareContext());

    expect($check->valid)->toBeFalse()
        ->and($check->warning)->toContain('401')
        ->and($api->callCount('/user/tokens/verify'))->toBe(1);
});

it('declares subscriptions and usage as cost capabilities', function () {
    $capabilities = cloudflareAdapter(new FakeCloudflareApi)->capabilities();

    expect($capabilities->supports(ProviderCapability::Inventory))->toBeTrue()
        ->and($capabilities->supports(ProviderCapability::Subscriptions))->toBeTrue()
        ->and($capabilities->supports(ProviderCapability::Usage))->toBeTrue()
        ->and($capabilities->supports(ProviderCapability::RenewalQuotes))->toBeFalse();
});

it('maps accounts and zones into canonical inventory', function () {
    $api = new FakeCloudflareApi([
        '/accounts' => cloudflareFixture('accounts.json'),
        '/zones' => cloudflareFixture('zones.json'),
    ]);

    $batch = cloudflareAdapter($api)->fetchInventory(cloudflareContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and(count($batch->items))->toBe(3);

    $account = $batch->items[0];

    expect($account->externalId)->toBe('account-synthetic-01')
        ->and($account->category)->toBe('account')
        ->and($account->name)->toBe('Synthetic Account');

    $zone = $batch->items[1];

    expect($zone->externalId)->toBe('zone-synthetic-01')
        ->and($zone->category)->toBe('dns')
        ->and($zone->name)->toBe('example-synthetic.com')
        ->and($zone->providerType)->toBe('zone');
});

it('degrades to a partial batch when the zone listing fails but keeps the accounts', function () {
    $api = (new FakeCloudflareApi([
        '/accounts' => cloudflareFixture('accounts.json'),
    ]))->throwOn('/zones', [
        new TransientProviderException('Cloudflare API server error for [/zones] (HTTP 503).'),
    ]);

    $batch = cloudflareAdapter($api)->fetchInventory(cloudflareContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and(count($batch->items))->toBe(1)
        ->and($batch->warnings)->toContain('zone listing failed: Cloudflare API server error for [/zones] (HTTP 503).');
});

it('walks paginated collections', function () {
    $pageOne = cloudflareEnvelope([['id' => 'zone-synthetic-01', 'name' => 'example-synthetic.com']]);
    $pageOne['result_info'] = ['page' => 1, 'per_page' => 1, 'count' => 1, 'total_pages' => 2, 'total_count' => 2];

    $api = new FakeCloudflareApi([
        '/accounts' => cloudflareFixture('accounts.json'),
        '/zones' => fn (array $query): array => ((int) ($query['page'] ?? 1)) === 1
            ? $pageOne
            : cloudflareEnvelope([['id' => 'zone-free-synthetic-02', 'name' => 'free-synthetic.org']]),
    ]);

    $batch = cloudflareAdapter($api)->fetchInventory(cloudflareContext());

    expect(count($batch->items))->toBe(3)
        ->and($api->callCount('/zones'))->toBe(2);
});

it('turns one subscription into one recurring cost fact', function () {
    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareFixture('account-subscriptions.json'),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareFixture('zone-subscriptions-a.json'),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and(count($batch->facts))->toBe(3);

    $workers = collect($batch->facts)->firstWhere('sourceRef', 'cf:subscription:sub-workers-synthetic-01');

    expect($workers)->not->toBeNull()
        ->and($workers->amount?->amountMinor)->toBe(500)
        ->and($workers->amount?->currency)->toBe('USD')
        ->and($workers->sourceKind)->toBe(SourceKind::Subscription)
        ->and($workers->chargeKind)->toBe(ChargeKind::RecurringFixed)
        ->and($workers->period)->toBe(Period::Monthly)
        ->and($workers->evidenceState)->toBe(EvidenceState::Actual)
        ->and($workers->renewsAt?->toDateString())->toBe('2026-10-01')
        ->and($workers->autoRenew)->toBeTrue()
        ->and($workers->serviceExternalIds)->toBe(['account-synthetic-01'])
        ->and($workers->notes)->toBe('Rate plan: Workers Paid');
});

it('keeps a free plan as a known zero, never as an unknown', function () {
    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareEnvelope([]),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareFixture('zone-subscriptions-b.json'),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    $free = collect($batch->facts)->first(fn ($fact): bool => $fact->amount !== null && $fact->amount->isZero());

    expect($free)->not->toBeNull()
        ->and($free->amount?->currency)->toBe('USD')
        ->and($free->sourceKind)->toBe(SourceKind::Subscription);
});

it('keeps a subscription with no fixed price honestly unknown', function () {
    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareFixture('account-subscriptions.json'),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareEnvelope([]),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    $unknown = collect($batch->facts)->first(fn ($fact): bool => $fact->amount === null);

    expect($unknown)->not->toBeNull()
        ->and($unknown?->sourceRef)->toBe('cf:subscription:sub-unknown-price-synthetic-02')
        ->and($batch->warnings)->toContain('1 subscription(s) have no fixed price or currency; their charges stay unknown.');
});

it('skips subscriptions in states it cannot price', function () {
    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareEnvelope([
            cloudflareSubscription(['id' => 'sub-trial-synthetic-03', 'state' => 'Trial']),
        ]),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareEnvelope([]),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    expect($batch->facts)->toBeEmpty()
        ->and($batch->warnings)->toContain('1 subscription(s) in a state other than paid or free were skipped.');
});

it('maps known frequencies and leaves unmapped ones unknown', function () {
    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareEnvelope([
            cloudflareSubscription(['id' => 'sub-q-synthetic', 'frequency' => 'quarterly']),
            cloudflareSubscription(['id' => 'sub-y-synthetic', 'frequency' => 'annually']),
            cloudflareSubscription(['id' => 'sub-u-synthetic', 'frequency' => 'every-blue-moon']),
        ]),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareEnvelope([]),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    $byRef = collect($batch->facts)->keyBy(fn ($fact): string => (string) $fact->sourceRef);

    expect($byRef['cf:subscription:sub-q-synthetic']->period)->toBe(Period::Quarterly)
        ->and($byRef['cf:subscription:sub-y-synthetic']->period)->toBe(Period::Annual)
        ->and($byRef['cf:subscription:sub-u-synthetic']->period)->toBe(Period::Unknown);
});

it('never duplicates a subscription reported by both listings', function () {
    $shared = cloudflareSubscription(['id' => 'sub-shared-synthetic']);

    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareEnvelope([$shared]),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareEnvelope([$shared]),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    expect(count($batch->facts))->toBe(1)
        ->and($batch->facts[0]->sourceRef)->toBe('cf:subscription:sub-shared-synthetic')
        ->and($batch->warnings)->toContain('1 duplicate subscription(s) across the account and zone listings were kept once.');
});

it('sums the fixed components of one subscription into one price', function () {
    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareEnvelope([
            cloudflareSubscription([
                'id' => 'sub-multi-synthetic',
                'rate_plan' => [
                    'id' => 'plan-multi',
                    'public_name' => 'Bundle',
                    'currency' => 'USD',
                    'components' => [
                        ['name' => 'Base', 'price' => '10.00'],
                        ['name' => 'Add-on', 'price' => '2.50'],
                        ['name' => 'Metered requests', 'metered' => true],
                    ],
                ],
            ]),
        ]),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareEnvelope([]),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    expect(count($batch->facts))->toBe(1)
        ->and($batch->facts[0]->amount?->amountMinor)->toBe(1250);
});

it('degrades the subscriptions capability when one listing fails', function () {
    $api = (new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareFixture('account-subscriptions.json'),
    ]))->throwOn('/zones/zone-synthetic-01/subscriptions', [
        new TransientProviderException('Cloudflare API server error for [/zones/zone-synthetic-01/subscriptions] (HTTP 503).'),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and($batch->completenessFor(ProviderCapability::Subscriptions))->toBe(BatchCompleteness::Partial)
        ->and(count($batch->facts))->toBe(2)
        ->and($batch->warnings[0])->toContain('[example-synthetic.com] failed');
});

it('always reports usage as partial so the total is never mistaken for complete', function () {
    $api = new FakeCloudflareApi([
        '/accounts/account-synthetic-01/subscriptions' => cloudflareFixture('account-subscriptions.json'),
        '/zones/zone-synthetic-01/subscriptions' => cloudflareFixture('zone-subscriptions-a.json'),
    ]);

    $batch = cloudflareAdapter($api)->fetchCostFacts(cloudflareContext(), cloudflareInventory());

    expect($batch->completenessFor(ProviderCapability::Usage))->toBe(BatchCompleteness::Partial)
        ->and($batch->warnings)->toContain('metered usage is unavailable — fixed subscriptions only; the Cloudflare total is not complete.');
});
