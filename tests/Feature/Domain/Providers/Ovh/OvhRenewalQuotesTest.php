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
 * The full run A payload plus the four family catalogs: the VPS and
 * cloud projects price (cloud at a known 0.00), the domain zone and
 * IP block have no matching plan and must stay unknown.
 */
function ovhQuoteFake(): FakeOvhApi
{
    $names = ovhFixture('service-run-a.json');
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

    return new FakeOvhApi($responses);
}

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

function chargeFor(ProviderAccount $account, string $name): CostItem
{
    return CostItem::query()
        ->where('logical_charge_key', sprintf('ovh:account:%d:charge:ovh:renewal:%s', $account->id, $name))
        ->sole();
}

// ---------------------------------------------------------------------------
// Adapter mapping
// ---------------------------------------------------------------------------

it('maps one renewal estimate per inventoried service', function () {
    $api = ovhQuoteFake();
    $adapter = new OvhProviderAdapter(new class($api) extends BuildOvhApi
    {
        public function __construct(private readonly OvhApi $api) {}

        public function build(array $payload): OvhApi
        {
            return $this->api;
        }
    });

    $context = new SyncContext(
        ProviderAccount::factory()->create(),
        ovhPayload(),
        new CarbonImmutable('2026-09-11 10:00:00'),
    );
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    app(ValidateBatches::class)->costFacts($costs, $inventory);

    expect($costs->facts)->toHaveCount(4)
        ->and($costs->completeness)->toBe(BatchCompleteness::Complete)
        ->and($costs->completenessFor(ProviderCapability::RenewalQuotes))
        ->toBe(BatchCompleteness::Complete)
        ->and($costs->completenessFor(ProviderCapability::Usage))
        ->toBe(BatchCompleteness::Unsupported);

    $byRef = collect($costs->facts)->keyBy(fn ($fact) => $fact->sourceRef);

    $vps = $byRef['ovh:renewal:vps-synthetic-01'];

    expect($vps->amount?->amountMinor)->toBe(700)
        ->and($vps->amount?->currency)->toBe('EUR')
        ->and($vps->evidenceState)->toBe(EvidenceState::Estimate)
        ->and($vps->period->value)->toBe('monthly')
        ->and($vps->taxBasis)->toBe(TaxBasis::Exclusive)
        ->and($vps->autoRenew)->toBeTrue()
        ->and($vps->renewsAt)->toBeNull()
        ->and($vps->serviceExternalIds)->toBe(['vps-synthetic-01'])
        ->and($vps->allocationState)->toBe(AllocationState::Direct)
        ->and($vps->validFrom->toDateString())->toBe('2026-09-11');

    // No matching plan in the domain catalog: the price stays unknown,
    // never inferred — but the coverage and the renewal strategy stay.
    $zone = $byRef['ovh:renewal:domain-zone-synthetic-01'];

    expect($zone->amount)->toBeNull()
        ->and($zone->renewsAt?->toDateString())->toBe('2026-10-20')
        ->and($zone->autoRenew)->toBeFalse();

    // A catalog price of 0.00 is a known price, not an absence.
    expect($byRef['ovh:renewal:cloud-project-synthetic-01']->amount?->amountMinor)->toBe(0)
        ->and($byRef['ovh:renewal:ip-synthetic-01']->amount)->toBeNull();
});

it('degrades the renewal capability when a catalog fetch fails', function () {
    // The service stays inventoried, so a failed pricing lookup must
    // surface as an unknown price over partial coverage — not as an
    // invented zero.
    $api = ovhQuoteFake()->throwOn('/order/catalog/formatted/vps', [
        new TransientProviderException('OVH rate limit reached for [/order/catalog/formatted/vps] (HTTP 429).'),
    ]);
    $adapter = new OvhProviderAdapter(new class($api) extends BuildOvhApi
    {
        public function __construct(private readonly OvhApi $api) {}

        public function build(array $payload): OvhApi
        {
            return $this->api;
        }
    });

    $context = new SyncContext(
        ProviderAccount::factory()->create(),
        ovhPayload(),
        new CarbonImmutable('2026-09-11 10:00:00'),
    );
    $inventory = $adapter->fetchInventory($context);
    $costs = $adapter->fetchCostFacts($context, $inventory);

    $vps = collect($costs->facts)->firstWhere(fn ($fact) => $fact->sourceRef === 'ovh:renewal:vps-synthetic-01');

    expect($costs->facts)->toHaveCount(4)
        ->and($costs->completeness)->toBe(BatchCompleteness::Partial)
        ->and($vps->amount)->toBeNull()
        ->and($costs->warnings)->toContain('renewal pricing unavailable for 1 services; catalog [vps] failed.');
});

// ---------------------------------------------------------------------------
// End to end through the orchestrator
// ---------------------------------------------------------------------------

it('persists renewal quotes as estimate charges with coverage and renewals', function () {
    $api = ovhQuoteFake();
    $account = ovhQuoteStack($api);

    $run = ovhQuoteSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['cost_facts'])->toBe(['seen' => 4, 'created' => 4, 'superseded' => 0, 'updated' => 0, 'renewals' => 1, 'ended' => 0]);

    $vps = chargeFor($account, 'vps-synthetic-01');

    expect($vps->source_kind->value)->toBe('renewal_quote')
        ->and($vps->evidence_state)->toBe(EvidenceState::Estimate)
        ->and($vps->amount_minor)->toBe(700)
        ->and($vps->currency)->toBe('EUR')
        ->and($vps->tax_basis)->toBe(TaxBasis::Exclusive)
        ->and($vps->allocation_state)->toBe(AllocationState::Direct)
        ->and($vps->services()->where('external_id', 'vps-synthetic-01')->exists())->toBeTrue();

    $zone = chargeFor($account, 'domain-zone-synthetic-01');
    expect($zone->amount_state->value)->toBe('unknown');

    $renewal = Renewal::query()->where('cost_item_id', $zone->id)->sole();
    expect($renewal->renews_at->toDateString())->toBe('2026-10-20')
        ->and($renewal->auto_renew)->toBeFalse();

    $renewalStates = ProviderCapabilityState::query()
        ->where('provider_account_id', $account->id)
        ->where('capability_key', 'renewal_quotes')
        ->sole();

    expect($renewalStates->supported)->toBeTrue()
        ->and($renewalStates->healthy)->toBeTrue()
        ->and($renewalStates->last_observed_at)->not->toBeNull();
});

it('projects quotes once with unknown prices counted, never added', function () {
    $api = ovhQuoteFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $result = app(CostProjector::class)->project(new CarbonImmutable(now()->format('Y-m-d')));
    $eur = $result->forCurrency('EUR');

    // 7.00 VPS + 0.00 cloud; the two unknown prices stay out of the sum
    // but are counted, and estimates switch the display to ~.
    expect($eur->monthlyMinor)->toBe(700)
        ->and($result->unknownCount)->toBe(2)
        // Only the two known charges count as estimates: unknown
    // prices are counted in unknownCount instead.
        ->and($result->estimateCount)->toBe(2)
        ->and($result->sharedUnallocatedCount)->toBe(0);
});

it('lets a manual override replace the quote without adding both', function () {
    $api = ovhQuoteFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $vps = chargeFor($account, 'vps-synthetic-01');

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

    // The override replaces the quote: 9.99 + 0.00, not 9.99 + 7.00.
    expect($result->forCurrency('EUR')->monthlyMinor)->toBe(999);
});

it('supersedes a synced charge when the catalog price changes', function () {
    $api = ovhQuoteFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $vps = chargeFor($account, 'vps-synthetic-01');
    expect($vps->amount_minor)->toBe(700);

    // The catalog reprices the VPS plan.
    $repriced = ovhFixture('catalog/vps-eu.json');
    $repriced['catalog'][0]['products'][0]['pricings'][0]['priceInUtv'] = 900;
    $repriced['catalog'][0]['products'][0]['pricings'][0]['price']['value'] = 9.0;
    $api->responses['/order/catalog/formatted/vps'] = $repriced;

    $this->travel(1)->day();
    $second = ovhQuoteSync($account);

    $open = $vps->refresh();

    expect($second->refresh()->counts['cost_facts']['superseded'])->toBe(1)
        // Same logical charge: the old version closed, the new one is open.
        ->and(CostItem::query()->where('logical_charge_key', $vps->logical_charge_key)->count())->toBe(2)
        ->and($open->valid_to)->not->toBeNull();

    $newVersion = CostItem::query()
        ->where('logical_charge_key', $vps->logical_charge_key)
        ->whereNull('valid_to')
        ->sole();
    expect($newVersion->amount_minor)->toBe(900)
        ->and($newVersion->id)->not->toBe($vps->id);
});

it('degrades to an unknown price when a catalog price is unparseable', function () {
    $api = ovhQuoteFake();

    // A three-decimal value without the minor-unit field cannot be
    // expressed exactly; it must degrade to unknown, not crash the run.
    $vpsCatalog = ovhFixture('catalog/vps-eu.json');
    unset($vpsCatalog['catalog'][0]['products'][0]['pricings'][0]['priceInUtv']);
    $vpsCatalog['catalog'][0]['products'][0]['pricings'][0]['price']['value'] = 0.0095;
    $api->responses['/order/catalog/formatted/vps'] = $vpsCatalog;

    $account = ovhQuoteStack($api);

    $run = ovhQuoteSync($account);

    $vps = chargeFor($account, 'vps-synthetic-01');

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and($vps->amount_state->value)->toBe('unknown')
        ->and($run->summary['warnings'] ?? [])->toContain('renewal price for [vps-synthetic-01] could not be parsed; left unknown.');
});

it('ends a renewal charge absent from a later complete run', function () {
    $api = ovhQuoteFake();
    $account = ovhQuoteStack($api);
    ovhQuoteSync($account);

    $ipCharge = chargeFor($account, 'ip-synthetic-01');
    expect($ipCharge->refresh()->valid_to)->toBeNull();

    // Run B is complete and no longer lists the IP block.
    $api->responses['/service'] = ovhFixture('service-run-b.json');
    unset($api->responses['/service/ip-synthetic-01']);

    $this->travel(1)->day();
    $second = ovhQuoteSync($account);

    expect($second->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($second->counts['cost_facts']['ended'])->toBe(1)
        ->and($ipCharge->refresh()->valid_to?->toDateString())->toBe('2026-09-10');
});
