<?php

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use App\Domain\Sync\Validation\ValidateBatches;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeOvhApi;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');

    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(OvhProviderAdapter::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The charge persisted for one renewal source reference.
 */
function chargeFor(ProviderAccount $account, string $ref): CostItem
{
    return CostItem::query()
        ->where('logical_charge_key', sprintf('ovh:account:%d:charge:%s', $account->id, $ref))
        ->sole();
}

/**
 * Register the real OVH adapter against a mutable fake client and
 * create an enabled account with credentials to match.
 */
function ovhQuoteStack(FakeOvhApi $api): ProviderAccount
{
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

    return $account;
}

function ovhQuoteSync(ProviderAccount $account): SyncRun
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

function ovhQuoteContext(ProviderAccount $account): SyncContext
{
    return new SyncContext($account, ovhPayload(), new CarbonImmutable('2026-09-11 10:00:00'));
}

it('maps one contracted estimate per inventoried service', function () {
    $api = ovhServicesFake();
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    app(ValidateBatches::class)->costFacts($costs, $inventory);

    expect($costs->facts)->toHaveCount(5)
        ->and($costs->completeness)->toBe(BatchCompleteness::Complete)
        ->and($costs->completenessFor(ProviderCapability::RenewalQuotes))
        ->toBe(BatchCompleteness::Complete)
        ->and($costs->completenessFor(ProviderCapability::Usage))
        ->toBe(BatchCompleteness::Unsupported)
        ->and($costs->completenessFor(ProviderCapability::Invoices))
        ->toBe(BatchCompleteness::Unsupported);

    $byRef = collect($costs->facts)->keyBy(fn ($fact) => $fact->sourceRef);

    $vps = $byRef['ovh:service:400010001'];

    expect($vps->amount?->amountMinor)->toBe(700)
        ->and($vps->amount?->currency)->toBe('EUR')
        ->and($vps->evidenceState)->toBe(EvidenceState::Estimate)
        ->and($vps->period->value)->toBe('monthly')
        ->and($vps->taxBasis)->toBe(TaxBasis::Exclusive)
        ->and($vps->autoRenew)->toBeTrue()
        ->and($vps->renewsAt?->toDateString())->toBe('2026-10-15')
        ->and($vps->serviceExternalIds)->toBe(['400010001'])
        ->and($vps->allocationState)->toBe(AllocationState::Direct)
        ->and($vps->notes)->toBe('contracted price from the service billing plan.')
        ->and($vps->validFrom->toDateString())->toBe('2026-09-11');

    $zone = $byRef['ovh:service:400010002'];

    expect($zone->amount?->amountMinor)->toBe(149)
        ->and($zone->allocationState)->toBe(AllocationState::Direct)
        ->and($zone->autoRenew)->toBeFalse();

    expect($byRef['ovh:service:400010003']->amount)->toBeNull();

    $ip = $byRef['ovh:service:400010004'];

    expect($ip->amount?->amountMinor)->toBe(500)
        ->and($ip->notes)->toBe('public catalog fallback; an estimate, not the account price.');

    expect($byRef['ovh:service:400010005']->amount?->amountMinor)->toBe(200)
        ->and($byRef['ovh:service:400010005']->serviceExternalIds)->toBe(['400010005']);
});

it('does not use a bundled /renew order preview as either sibling\'s charge', function () {
    $api = ovhServicesFake();
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $coveringVps = collect($costs->facts)
        ->filter(fn ($fact) => in_array('400010001', $fact->serviceExternalIds, true));

    expect($coveringVps)->toHaveCount(1)
        ->and($coveringVps->first()->sourceRef)->toBe('ovh:service:400010001')
        ->and($coveringVps->first()->amount?->amountMinor)->toBe(700)
        ->and($coveringVps->first()->allocationState)->toBe(AllocationState::Direct);

    expect(collect($costs->facts)->firstWhere('sourceRef', 'ovh:service:400010005')->amount?->amountMinor)->toBe(200)
        ->and($costs->facts)->toHaveCount(5);
});

it('does not call /service/{id}/renew when billing.pricing is present', function () {
    $api = ovhServicesFake();
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $adapter->fetchCostFacts($context, $inventory);

    expect($api->callCount('/service/400010001/renew'))->toBe(0)
        ->and($api->callCount('/service/400010002/renew'))->toBe(0)
        ->and($api->callCount('/service/400010005/renew'))->toBe(0)
        ->and($api->callCount('/service/400010004/renew'))->toBe(1);
});

it('falls back to a solo /renew strategy when billing.pricing is missing', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010002']['billing']['pricing'] = null;

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010002');

    expect($zone->amount?->amountMinor)->toBe(149)
        ->and($zone->notes)->toBe('renewal quote from the solo /service/{id}/renew strategy.')
        ->and($api->callCount('/service/400010002/renew'))->toBe(1);
});

it('ignores a bundled-only /renew preview instead of inflating the service', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010001']['billing']['pricing'] = null;
    $api->responses['/service/400010001/renew'] = [[
        'renewPeriod' => 'P1M',
        'strategies' => [[
            'services' => [400010001, 400010005],
            'price' => ['currencyCode' => 'EUR', 'text' => '9.00 €', 'value' => 9.0],
            'priceInUcents' => 900000000,
        ]],
    ]];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $vps = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010001');

    expect($vps->amount?->amountMinor)->toBe(700)
        ->and($vps->notes)->toBe('public catalog fallback; an estimate, not the account price.')
        ->and($costs->warnings)->toContain('renewal strategy for [400010001] is a multi-service order preview and is not used as its contracted price.');
});

it('does not add a foreign service from a /renew strategy into the covered charge', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010002']['billing']['pricing'] = null;
    $api->responses['/service/400010002/renew'] = [[
        'renewPeriod' => 'P1M',
        'strategies' => [[
            'services' => [400010002, 999999999],
            'price' => ['currencyCode' => 'EUR', 'text' => '51.49 €', 'value' => 51.49],
            'priceInUcents' => 5149000000,
        ]],
    ]];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010002');

    expect($zone->amount)->toBeNull()
        ->and($zone->serviceExternalIds)->toBe(['400010002'])
        ->and($costs->warnings)->toContain('renewal strategy for [400010002] is a multi-service order preview and is not used as its contracted price.');
});

it('keeps an empty /renew payload from reading as cancellation', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010002']['billing']['pricing'] = null;
    $api->responses['/service/400010002/renew'] = [];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010002');

    expect($zone)->not->toBeNull()
        ->and($zone->serviceExternalIds)->toBe(['400010002'])
        ->and($zone->amount)->toBeNull();
});

it('warns when several solo /renew strategies could price the same service', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010002']['billing']['pricing'] = null;
    $api->responses['/service/400010002/renew'] = [[
        'renewPeriod' => 'P1M',
        'strategies' => [
            [
                'services' => [400010002],
                'price' => ['currencyCode' => 'EUR', 'text' => '1.49 €', 'value' => 1.49],
                'priceInUcents' => 149000000,
            ],
            [
                'services' => [400010002],
                'price' => ['currencyCode' => 'EUR', 'text' => '14.90 €', 'value' => 14.9],
                'priceInUcents' => 1490000000,
            ],
        ],
    ]];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010002');

    expect($zone->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal price for [400010002] is ambiguous; left unknown.');
});

it('treats a malformed /renew body as a failed fetch', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010004']['billing']['pricing'] = null;
    $api->responses['/service/400010004/renew'] = 'not-a-list';

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $ip = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010004');

    expect($ip->amount?->amountMinor)->toBe(500)
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal quote unavailable for [400010004]; the strategy fetch failed.');
});

it('requests the catalog for the account subsidiary from /me', function () {
    $api = ovhServicesFake();
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $adapter->fetchCostFacts($context, $inventory);

    expect($api->queries['/order/catalog/formatted/ip'])->toContain(['ovhSubsidiary' => 'IE']);
});

it('warns for the public cloud coverage gap without a catalog or renew call', function () {
    $api = ovhServicesFake();
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $catalogCalls = array_filter(
        $api->calls,
        fn (string $path): bool => str_contains($path, '/order/catalog/formatted/cloud'),
    );

    expect($catalogCalls)->toBe([])
        ->and($api->callCount('/service/400010003/renew'))->toBe(0)
        ->and($costs->warnings)->toContain('public cloud usage and billing are not yet synchronized; [400010003] stays unknown.');
});

it('warns when a contracted price is not in the account currency', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010002']['billing']['pricing']['price']['currencyCode'] = 'USD';

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010002');

    expect($zone->amount?->currency)->toBe('USD')
        ->and($costs->completeness)->toBe(BatchCompleteness::Complete)
        ->and($costs->warnings)->toContain('renewal price for [400010002] is in USD, not the account currency EUR.');
});

it('degrades to an unknown price when ucents are not aligned to minor units', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010002']['billing']['pricing']['priceInUcents'] = 149000;
    unset($api->responses['/services/400010002']['billing']['pricing']['price']['priceInUcents']);
    unset($api->responses['/services/400010002']['billing']['pricing']['price']['value']);

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010002');

    expect($zone->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal price for [400010002] could not be parsed; left unknown.');
});

it('degrades the capability when a /renew fallback fetch fails transiently', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010004']['billing']['pricing'] = null;
    $api->throwOn('/service/400010004/renew', [
        new TransientProviderException('OVH API server error for [/service/400010004/renew] (HTTP 503).'),
    ]);
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $ip = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010004');

    expect($ip->amount?->amountMinor)->toBe(500)
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal quote unavailable for [400010004]; the strategy fetch failed.');
});

it('degrades when the fallback catalog fetch fails', function () {
    $api = ovhServicesFake()->throwOn('/order/catalog/formatted/ip', [
        new TransientProviderException('OVH rate limit reached for [/order/catalog/formatted/ip] (HTTP 429).'),
    ]);
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $ip = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010004');

    expect($ip->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal pricing unavailable for [400010004]; catalog [ip] failed.');
});

it('warns when no catalog plan matches the fallback', function () {
    $api = ovhServicesFake();
    array_pop($api->responses['/order/catalog/formatted/ip']['plans']);

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $ip = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:service:400010004');

    expect($ip->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('no matching catalog plan for [400010004]; price left unknown.');
});

it('skips the catalog subsidiary and currency check when /me fails', function () {
    $api = ovhServicesFake()->throwOn('/me', [
        new TransientProviderException('OVH rate limit reached for [/me] (HTTP 429).'),
    ]);
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    expect($api->queries['/order/catalog/formatted/ip'])->toContain([])
        ->and($costs->warnings)->toContain('account identity [/me] is unavailable; the catalog subsidiary and currency check are skipped.');
});

it('persists contracted quotes as estimate charges with renewal dates', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);

    $run = ovhQuoteSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['cost_facts'])->toBe(['seen' => 5, 'created' => 5, 'superseded' => 0, 'updated' => 0, 'renewals' => 4, 'ended' => 0]);

    $vps = chargeFor($account, 'ovh:service:400010001');

    expect($vps->source_kind->value)->toBe('renewal_quote')
        ->and($vps->evidence_state)->toBe(EvidenceState::Estimate)
        ->and($vps->amount_minor)->toBe(700)
        ->and($vps->currency)->toBe('EUR')
        ->and($vps->tax_basis)->toBe(TaxBasis::Exclusive)
        ->and($vps->allocation_state->value)->toBe('direct')
        ->and($vps->services()->pluck('external_id')->all())->toBe(['400010001']);

    expect(chargeFor($account, 'ovh:service:400010002')->amount_minor)->toBe(149)
        ->and(chargeFor($account, 'ovh:service:400010003')->refresh()->amount_state->value)->toBe('unknown')
        ->and(chargeFor($account, 'ovh:service:400010004')->amount_minor)->toBe(500)
        ->and(chargeFor($account, 'ovh:service:400010005')->amount_minor)->toBe(200);

    expect(Renewal::query()->count())->toBe(4)
        ->and($vps->renewal?->renews_at?->toDateString())->toBe('2026-10-15')
        ->and($vps->renewal?->auto_renew)->toBeTrue();

    $renewalStates = ProviderCapabilityState::query()
        ->where('provider_account_id', $account->id)
        ->where('capability_key', 'renewal_quotes')
        ->sole();

    expect($renewalStates->supported)->toBeTrue()
        ->and($renewalStates->healthy)->toBeTrue()
        ->and($renewalStates->last_observed_at)->not->toBeNull();
});

it('projects quotes once with unknown prices counted, never added', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $result = app(CostProjector::class)->project(new CarbonImmutable(now()->format('Y-m-d')));
    $eur = $result->forCurrency('EUR');

    expect($eur->monthlyMinor)->toBe(1549)
        ->and($result->unknownCount)->toBe(1)
        ->and($result->estimateCount)->toBe(4)
        ->and($result->sharedUnallocatedCount)->toBe(0);
});

it('lets a manual override replace the quote without adding both', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $vps = chargeFor($account, 'ovh:service:400010001');

    CostItem::query()->create([
        'identity_key' => $vps->identity_key.':override',
        'logical_charge_key' => $vps->logical_charge_key,
        'source_kind' => 'manual',
        'charge_kind' => 'recurring_fixed',
        'period' => 'monthly',
        'amount_minor' => 999,
        'currency' => 'EUR',
        'amount_state' => 'known',
        'evidence_state' => 'manual',
        'is_manual_override' => true,
        'valid_from' => now()->startOfDay()->subDays(10),
        'observed_at' => now(),
    ]);

    $result = app(CostProjector::class)->project(new CarbonImmutable(now()->format('Y-m-d')));

    expect($result->forCurrency('EUR')->monthlyMinor)->toBe(1848);
});

it('supersedes a synced charge when the fallback catalog price changes', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $ip = chargeFor($account, 'ovh:service:400010004');
    expect($ip->amount_minor)->toBe(500);

    $repriced = ovhFixture('catalog/ip-eu.json');
    $repriced['plans'][1]['details']['pricings']['default'][0]['priceInUcents'] = 800000000;
    $repriced['plans'][1]['details']['pricings']['default'][0]['price']['value'] = 8.0;
    $api->responses['/order/catalog/formatted/ip'] = $repriced;

    $this->travel(1)->day();
    $second = ovhQuoteSync($account);

    $open = $ip->refresh();

    expect($second->refresh()->counts['cost_facts']['superseded'])->toBe(1)
        ->and(CostItem::query()->where('logical_charge_key', $ip->logical_charge_key)->count())->toBe(2)
        ->and($open->valid_to)->not->toBeNull();

    $newVersion = CostItem::query()
        ->where('logical_charge_key', $ip->logical_charge_key)
        ->whereNull('valid_to')
        ->sole();
    expect($newVersion->amount_minor)->toBe(800)
        ->and($newVersion->id)->not->toBe($ip->id);
});

it('degrades the renewal capability end to end when the fallback catalog fetch fails', function () {
    $api = ovhServicesFake()->throwOn('/order/catalog/formatted/ip', array_fill(
        0,
        (int) config('sync.retry.max_attempts'),
        new TransientProviderException('OVH rate limit reached for [/order/catalog/formatted/ip] (HTTP 429).'),
    ));
    $account = ovhQuoteStack($api);

    $run = ovhQuoteSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and(chargeFor($account, 'ovh:service:400010004')->refresh()->amount_state->value)->toBe('unknown')
        ->and($run->summary['warnings'] ?? [])->toContain('renewal pricing unavailable for [400010004]; catalog [ip] failed.');
});

it('ends a renewal charge absent from a later complete run', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $ipCharge = chargeFor($account, 'ovh:service:400010004');
    expect($ipCharge->refresh()->valid_to)->toBeNull();

    $api->responses = ovhServicesFake('services-run-b.json')->responses;

    $this->travel(1)->day();
    $second = ovhQuoteSync($account);

    expect($second->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($second->counts['cost_facts']['ended'])->toBe(1)
        ->and($ipCharge->refresh()->valid_to?->toDateString())->toBe('2026-09-10');
});
