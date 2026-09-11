<?php

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Dtos\CostFact;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Support\ValueObjects\Money;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeProviderAdapter;

beforeEach(function () {
    $this->freezeTime();
    $this->app->forgetInstance(AdapterRegistry::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function allocationFact(string $sourceRef, array $covers, ?Money $amount, ?AllocationState $allocation = null): CostFact
{
    return new CostFact(
        sourceRef: $sourceRef,
        serviceExternalIds: $covers,
        sourceKind: SourceKind::RenewalQuote,
        chargeKind: ChargeKind::RecurringFixed,
        period: Period::Monthly,
        evidenceState: EvidenceState::Estimate,
        amount: $amount,
        validFrom: now()->startOfDay(),
        allocationState: $allocation ?? AllocationState::Direct,
    );
}

/**
 * One account synced once with a package fact covering two services
 * and a single-service fact beside it.
 */
function allocationStack(): ProviderAccount
{
    $account = ProviderAccount::factory()->create(['provider_key' => 'fakeprovider']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id]);

    $inventory = new InventoryBatch(
        BatchCompleteness::Complete,
        now(),
        'fake:inventory',
        [
            new InventoryItem('svc-a', 'compute', 'svc-a'),
            new InventoryItem('svc-b', 'storage', 'svc-b'),
        ],
    );

    $costs = new CostFactBatch(
        BatchCompleteness::Complete,
        now(),
        'fake:cost-facts',
        [
            allocationFact('pkg-1', ['svc-a', 'svc-b'], Money::ofMinor(1000, 'EUR'), AllocationState::SharedUnallocated),
            allocationFact('solo-1', ['svc-a'], Money::ofMinor(250, 'EUR')),
        ],
    );

    app(AdapterRegistry::class)->register('fakeprovider', new FakeProviderAdapter(
        inventoryBatch: $inventory,
        costFactBatch: $costs,
    ));

    return $account;
}

function allocationSync(ProviderAccount $account): SyncRun
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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('persists a shared package fact as one cost item covering every service', function () {
    $account = allocationStack();

    $run = allocationSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['cost_facts'])->toBe(['seen' => 2, 'created' => 2, 'superseded' => 0, 'updated' => 0, 'renewals' => 0, 'ended' => 0]);

    $package = CostItem::query()
        ->where('logical_charge_key', 'like', '%charge:pkg-1')
        ->sole();

    expect($package->allocation_state)->toBe(AllocationState::SharedUnallocated)
        ->and($package->amount_minor)->toBe(1000)
        ->and($package->services()->count())->toBe(2);

    $solo = CostItem::query()
        ->where('logical_charge_key', 'like', '%charge:solo-1')
        ->sole();

    expect($solo->allocation_state)->toBe(AllocationState::Direct)
        ->and($solo->services()->count())->toBe(1);
});

it('counts a shared package once and reports it as unallocated', function () {
    $account = allocationStack();

    allocationSync($account);

    $result = app(CostProjector::class)->project(new CarbonImmutable(now()->format('Y-m-d')));

    // The package price appears exactly once, next to the solo charge:
    // 10.00 + 2.50 = 12.50 EUR, with the package counted as unallocated.
    expect($result->forCurrency('EUR')->monthlyMinor)->toBe(1250)
        ->and($result->sharedUnallocatedCount)->toBe(1)
        ->and($result->unknownCount)->toBe(0)
        ->and($result->estimateCount)->toBe(2);
});

it('updates allocation in place without superseding the price version', function () {
    $account = allocationStack();
    allocationSync($account);

    $package = CostItem::query()->where('logical_charge_key', 'like', '%charge:pkg-1')->sole();

    // Same price, allocation now certain: the version stays open.
    $reallocated = new CostFactBatch(
        BatchCompleteness::Complete,
        now()->addDay(),
        'fake:cost-facts',
        [allocationFact('pkg-1', ['svc-a', 'svc-b'], Money::ofMinor(1000, 'EUR'), AllocationState::Allocated)],
    );

    /** @var FakeProviderAdapter $adapter */
    $adapter = app(AdapterRegistry::class)->for('fakeprovider');
    $adapter->costFactBatch = $reallocated;

    $second = allocationSync($account);

    $package->refresh();

    // The second batch only reports pkg-1, so the absent solo
    // charge is ended — the package itself is updated in place.
    expect($second->refresh()->counts['cost_facts'])->toBe(['seen' => 1, 'created' => 0, 'superseded' => 0, 'updated' => 1, 'renewals' => 0, 'ended' => 1])
        ->and($package->allocation_state)->toBe(AllocationState::Allocated)
        ->and($package->valid_to)->toBeNull()
        ->and(CostItem::query()->where('logical_charge_key', 'like', '%charge:pkg-1')->count())->toBe(1);
});

it('shows package membership on the service rows', function () {
    $account = allocationStack();
    allocationSync($account);

    $serviceA = Service::query()->where('external_id', 'svc-a')->sole();
    $serviceB = Service::query()->where('external_id', 'svc-b')->sole();

    expect($serviceA->costItems()->count())->toBe(2)
        ->and($serviceB->costItems()->count())->toBe(1)
        ->and($serviceB->costItems()->sole()->logical_charge_key)->toContain('pkg-1');
});
