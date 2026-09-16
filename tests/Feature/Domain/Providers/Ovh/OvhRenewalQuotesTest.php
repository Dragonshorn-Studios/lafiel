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

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Adapter mapping
// ---------------------------------------------------------------------------

it('maps one renewal estimate per strategy', function () {
    $api = ovhServicesFake();
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    app(ValidateBatches::class)->costFacts($costs, $inventory);

    expect($costs->facts)->toHaveCount(4)
        // The standing Public Cloud gap is an honest unknown on an
        // otherwise complete capability, not a degraded fetch.
        ->and($costs->completeness)->toBe(BatchCompleteness::Complete)
        ->and($costs->completenessFor(ProviderCapability::RenewalQuotes))
        ->toBe(BatchCompleteness::Complete)
        ->and($costs->completenessFor(ProviderCapability::Usage))
        ->toBe(BatchCompleteness::Unsupported)
        ->and($costs->completenessFor(ProviderCapability::Invoices))
        ->toBe(BatchCompleteness::Unsupported);

    $byRef = collect($costs->facts)->keyBy(fn ($fact) => $fact->sourceRef);

    $vps = $byRef['ovh:renew:400010001+400010005'];

    expect($vps->amount?->amountMinor)->toBe(900)
        ->and($vps->amount?->currency)->toBe('EUR')
        ->and($vps->evidenceState)->toBe(EvidenceState::Estimate)
        ->and($vps->period->value)->toBe('monthly')
        ->and($vps->taxBasis)->toBe(TaxBasis::Exclusive)
        ->and($vps->autoRenew)->toBeTrue()
        ->and($vps->renewsAt)->toBeNull()
        ->and($vps->serviceExternalIds)->toBe(['400010001', '400010005'])
        ->and($vps->allocationState)->toBe(AllocationState::SharedUnallocated)
        ->and($vps->validFrom->toDateString())->toBe('2026-09-11');

    $zone = $byRef['ovh:renew:400010002'];

    expect($zone->amount?->amountMinor)->toBe(149)
        ->and($zone->allocationState)->toBe(AllocationState::Direct)
        ->and($zone->autoRenew)->toBeFalse();

    // A Public Cloud project is never priced: its cost is usage, which
    // is a separate, explicitly unsupported capability.
    expect($byRef['ovh:renew:400010003']->amount)->toBeNull();

    // The IP block has no strategy price, so the public catalog prices
    // it as an explicitly marked fallback estimate.
    $ip = $byRef['ovh:renew:400010004'];

    expect($ip->amount?->amountMinor)->toBe(500)
        ->and($ip->notes)->toBe('public catalog fallback; an estimate, not the account price.');
});

it('never duplicates a multi-service strategy price onto each service', function () {
    $api = ovhServicesFake();
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $covering = collect($costs->facts)
        ->filter(fn ($fact) => in_array('400010001', $fact->serviceExternalIds, true));

    expect($covering)->toHaveCount(1)
        ->and($covering->first()->sourceRef)->toBe('ovh:renew:400010001+400010005')
        ->and($covering->first()->serviceExternalIds)->toBe(['400010001', '400010005'])
        ->and($covering->first()->amount?->amountMinor)->toBe(900)
        ->and($covering->first()->allocationState)->toBe(AllocationState::SharedUnallocated)
        ->and($costs->facts)->toHaveCount(4);
});

it('keeps an odd strategy payload from reading as cancellation', function () {
    // The payload lists no services: the service keeps its own unknown
    // quote, so the charge stays reported and no ending pass can treat
    // the odd payload as a cancellation.
    $api = ovhServicesFake();
    $api->responses['/service/400010002/renew'] = ['services' => [], 'options' => [], 'prices' => []];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    expect($zone)->not->toBeNull()
        ->and($zone->serviceExternalIds)->toBe(['400010002'])
        ->and($zone->amount)->toBeNull()
        ->and($costs->warnings)->not->toContain('renewal strategy for [400010002] covers none of this run\'s services; price left unknown.');
});

it('warns when a strategy lists only services outside the inventory', function () {
    $api = ovhServicesFake();
    $api->responses['/service/400010002/renew']['services'][0]['serviceId'] = 999999999;

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    expect($zone->serviceExternalIds)->toBe(['400010002'])
        ->and($zone->amount)->toBeNull()
        ->and($costs->warnings)->toContain('renewal strategy for [400010002] covers none of this run\'s services; price left unknown.');
});

it('links only covered services and reads the period from them', function () {
    $api = ovhServicesFake();

    // A foreign entry speaks first with an annual period and no
    // auto-renew; the fact must take period and flag from the covered
    // services only.
    $vpsRenew = &$api->responses['/service/400010001/renew'];
    array_unshift($vpsRenew['services'], [
        'serviceId' => 999999999,
        'serviceName' => 'foreign-synthetic-01',
        'selectedPrice' => 'vps-essentials-2025 P1M',
        'renew' => ['automatic' => false, 'period' => 'P12M'],
    ]);

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $combined = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010001+400010005');

    expect($combined->serviceExternalIds)->toBe(['400010001', '400010005'])
        ->and($combined->amount?->amountMinor)->toBe(900)
        ->and($combined->period->value)->toBe('monthly')
        ->and($combined->autoRenew)->toBeTrue()
        ->and($costs->warnings)->toContain('renewal strategy for [400010001] covers [999999999], which is outside this run\'s inventory; it is not linked.');
});

it('does not add a foreign service price into the covered strategy', function () {
    $api = ovhServicesFake();

    // A distinct selectedPrice on an outside-inventory entry must not
    // inflate the fact whose coverage excludes that service.
    $vpsRenew = &$api->responses['/service/400010001/renew'];
    array_unshift($vpsRenew['services'], [
        'serviceId' => 999999999,
        'serviceName' => 'foreign-synthetic-01',
        'selectedPrice' => 'foreign-addon-2025 P1M',
        'renew' => ['automatic' => false, 'period' => 'P1M'],
    ]);
    $vpsRenew['prices'][] = [
        'label' => 'foreign-addon-2025 P1M',
        'duration' => 'P1M',
        'price' => ['currencyCode' => 'EUR', 'text' => '50.00', 'value' => 50.0],
        'priceInUtv' => 5000,
        'tax' => ['mode' => 'vat-excluded'],
    ];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $combined = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010001+400010005');

    expect($combined->amount?->amountMinor)->toBe(900)
        ->and($costs->warnings)->toContain('renewal strategy for [400010001] covers [999999999], which is outside this run\'s inventory; it is not linked.');
});

it('warns when sibling renew payloads disagree on the same covered set', function () {
    $api = ovhServicesFake();

    // 400010001 is listed first, so its 9.00 quote is the observation
    // that must be kept. The failover sibling selects a different
    // extra label and would otherwise silently replace or inflate it.
    $failover = &$api->responses['/service/400010005/renew'];
    $failover['services'][0]['selectedPrice'] = 'foreign-addon-2025 P1M';
    $failover['prices'][] = [
        'label' => 'foreign-addon-2025 P1M',
        'duration' => 'P1M',
        'price' => ['currencyCode' => 'EUR', 'text' => '50.00', 'value' => 50.0],
        'priceInUtv' => 5000,
        'tax' => ['mode' => 'vat-excluded'],
    ];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $combined = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010001+400010005');

    expect($combined->amount?->amountMinor)->toBe(900)
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal strategy for [400010005] disagrees with the already recorded strategy covering the same services; the first observation is kept.');
});

it('refuses to sum strategy prices from different duration buckets', function () {
    $api = ovhServicesFake();

    // The failover part renews annually; summing it into a monthly
    // fact would overstate monthly spend.
    $api->responses['/service/400010001/renew']['prices'][1]['duration'] = 'P12M';

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $combined = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010001+400010005');

    // The unusable strategy degrades, and the public catalog takes over
    // as a marked fallback estimate for the VPS plan alone.
    expect($combined->amount?->amountMinor)->toBe(700)
        ->and($combined->notes)->toBe('public catalog fallback; an estimate, not the account price.')
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal price for [400010001] mixes renewal periods; left unknown.');
});

it('refuses to sum strategy prices in different currencies', function () {
    $api = ovhServicesFake();
    $api->responses['/service/400010001/renew']['prices'][1]['price']['currencyCode'] = 'USD';

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $combined = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010001+400010005');

    // Mixed currencies throw out of the exact-money sum; the public
    // catalog takes over as a marked fallback estimate.
    expect($combined->amount?->amountMinor)->toBe(700)
        ->and($combined->notes)->toBe('public catalog fallback; an estimate, not the account price.')
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal price for [400010001] could not be parsed; left unknown.');
});

it('warns when a selected price label has no price part', function () {
    $api = ovhServicesFake();
    array_shift($api->responses['/service/400010002/renew']['prices']);

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    expect($zone->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal price [zone-2025 P1M] selected for [400010002] has no price part; price left unknown.');
});

it('prices a strategy that selects nothing but carries exactly one price', function () {
    $api = ovhServicesFake();
    $api->responses['/service/400010002/renew']['services'][0]['selectedPrice'] = null;

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    expect($zone->amount?->amountMinor)->toBe(149)
        ->and($zone->notes)->toBe('renewal quote from the service renewal strategy.');
});

it('keeps an unknown tax basis when a strategy price is not vat-excluded', function () {
    $api = ovhServicesFake();
    $api->responses['/service/400010002/renew']['prices'][0]['tax']['mode'] = 'vat-included';

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    expect($zone->amount?->amountMinor)->toBe(149)
        ->and($zone->taxBasis)->toBe(TaxBasis::Unknown);
});

it('warns when a strategy price is not in the account currency', function () {
    $api = ovhServicesFake();
    $api->responses['/service/400010002/renew']['prices'][0]['price']['currencyCode'] = 'USD';

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    // A foreign currency is surfaced, not silently converted — and it
    // is a warning, not a coverage gap.
    expect($zone->amount?->currency)->toBe('USD')
        ->and($costs->completeness)->toBe(BatchCompleteness::Complete)
        ->and($costs->warnings)->toContain('renewal price for [400010002] is in USD, not the account currency EUR.');
});

it('treats a malformed strategy body as a failed fetch', function () {
    $api = ovhServicesFake();
    $api->responses['/service/400010002/renew'] = 'not-a-list';

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    expect(collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002'))->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal quote unavailable for [400010002]; the strategy fetch failed.');
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

it('warns for the public cloud coverage gap without a catalog call', function () {
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
        ->and($costs->warnings)->toContain('public cloud usage and billing are not yet synchronized; [400010003] stays unknown.');
});

it('reports an ambiguous strategy price as unknown, never guessed', function () {
    $api = ovhServicesFake();
    $api->responses['/service/400010002/renew']['services'][0]['selectedPrice'] = null;
    $api->responses['/service/400010002/renew']['prices'][] = [
        'label' => 'zone-2025 P12M',
        'duration' => 'P12M',
        'price' => ['currencyCode' => 'EUR', 'text' => '14.90', 'value' => 14.9],
        'priceInUtv' => 1490,
    ];

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    expect($zone->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal price for [400010002] is ambiguous; left unknown.');
});

it('degrades to an unknown price when a strategy price is unparseable', function () {
    $api = ovhServicesFake();

    // A three-decimal value without the minor-unit field cannot be
    // expressed exactly; it must degrade to unknown, not crash the run.
    unset($api->responses['/service/400010002/renew']['prices'][0]['priceInUtv']);
    $api->responses['/service/400010002/renew']['prices'][0]['price']['value'] = 0.0095;

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $zone = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010002');

    expect($zone->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal price for [400010002] could not be parsed; left unknown.');
});

it('degrades the capability when a strategy fetch fails transiently', function () {
    $api = ovhServicesFake()->throwOn('/service/400010002/renew', [
        new TransientProviderException('OVH API server error for [/service/400010002/renew] (HTTP 503).'),
    ]);
    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    expect($costs->facts)->toHaveCount(3)
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal quote unavailable for [400010002]; the strategy fetch failed.');
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

    $ip = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010004');

    expect($ip->amount)->toBeNull()
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($costs->warnings)->toContain('renewal pricing unavailable for [400010004]; catalog [ip] failed.');
});

it('warns when no catalog plan matches the fallback', function () {
    $api = ovhServicesFake();
    array_pop($api->responses['/order/catalog/formatted/ip']['catalog'][0]['products']);

    $adapter = ovhAdapter($api);

    $account = ProviderAccount::factory()->create();
    $context = ovhQuoteContext($account);
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $ip = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renew:400010004');

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

// ---------------------------------------------------------------------------
// End to end through the orchestrator
// ---------------------------------------------------------------------------

it('persists renewal quotes as estimate charges with multi-service coverage', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);

    $run = ovhQuoteSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['cost_facts'])->toBe(['seen' => 4, 'created' => 4, 'superseded' => 0, 'updated' => 0, 'renewals' => 0, 'ended' => 0]);

    $combined = chargeFor($account, 'ovh:renew:400010001+400010005');

    expect($combined->source_kind->value)->toBe('renewal_quote')
        ->and($combined->evidence_state)->toBe(EvidenceState::Estimate)
        ->and($combined->amount_minor)->toBe(900)
        ->and($combined->currency)->toBe('EUR')
        ->and($combined->tax_basis)->toBe(TaxBasis::Exclusive)
        ->and($combined->allocation_state->value)->toBe('shared_unallocated')
        ->and($combined->services()->pluck('external_id')->sort()->values()->all())
        ->toBe(['400010001', '400010005']);

    expect(chargeFor($account, 'ovh:renew:400010002')->amount_minor)->toBe(149)
        ->and(chargeFor($account, 'ovh:renew:400010003')->refresh()->amount_state->value)->toBe('unknown')
        ->and(chargeFor($account, 'ovh:renew:400010004')->amount_minor)->toBe(500);

    // No renewal strategy carries a trustworthy next-billing date, so
    // no renewal rows are written yet (documented v1 gap).
    expect(Renewal::query()->count())->toBe(0);

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

    // 9.00 combined strategy + 1.49 zone + 5.00 fallback; the cloud
    // unknown stays out of the sum but is counted, and estimates switch
    // the display to ~. The combined price appears exactly once.
    expect($eur->monthlyMinor)->toBe(1549)
        ->and($result->unknownCount)->toBe(1)
        ->and($result->estimateCount)->toBe(3)
        ->and($result->sharedUnallocatedCount)->toBe(1);
});

it('lets a manual override replace the quote without adding both', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $combined = chargeFor($account, 'ovh:renew:400010001+400010005');

    CostItem::query()->create([
        'identity_key' => $combined->identity_key.':override',
        'logical_charge_key' => $combined->logical_charge_key,
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

    // The override replaces the combined quote: 9.99 + 1.49 + 5.00.
    expect($result->forCurrency('EUR')->monthlyMinor)->toBe(1648);
});

it('supersedes a synced charge when the fallback catalog price changes', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $ip = chargeFor($account, 'ovh:renew:400010004');
    expect($ip->amount_minor)->toBe(500);

    // The catalog reprices the IP block plan.
    $repriced = ovhFixture('catalog/ip-eu.json');
    $repriced['catalog'][0]['products'][1]['pricings'][0]['priceInUtv'] = 800;
    $repriced['catalog'][0]['products'][1]['pricings'][0]['price']['value'] = 8.0;
    $api->responses['/order/catalog/formatted/ip'] = $repriced;

    $this->travel(1)->day();
    $second = ovhQuoteSync($account);

    $open = $ip->refresh();

    expect($second->refresh()->counts['cost_facts']['superseded'])->toBe(1)
        // Same logical charge: the old version closed, the new one is open.
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
        ->and(chargeFor($account, 'ovh:renew:400010004')->refresh()->amount_state->value)->toBe('unknown')
        ->and($run->summary['warnings'] ?? [])->toContain('renewal pricing unavailable for [400010004]; catalog [ip] failed.');
});

it('ends a renewal charge absent from a later complete run', function () {
    $api = ovhServicesFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $ipCharge = chargeFor($account, 'ovh:renew:400010004');
    expect($ipCharge->refresh()->valid_to)->toBeNull();

    // Run B is complete and no longer lists the IP block.
    $api->responses = ovhServicesFake('services-run-b.json')->responses;

    $this->travel(1)->day();
    $second = ovhQuoteSync($account);

    expect($second->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($second->counts['cost_facts']['ended'])->toBe(1)
        ->and($ipCharge->refresh()->valid_to?->toDateString())->toBe('2026-09-10');
});
