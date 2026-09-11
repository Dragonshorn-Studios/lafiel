<?php

namespace Tests\Feature\Domain\Inventory;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Dtos\CostFact;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Support\ValueObjects\Money;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeProviderAdapter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    $this->app->forgetInstance(AdapterRegistry::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function lifecycleAccount(FakeProviderAdapter $adapter, array $credentialAttributes = []): ProviderAccount
{
    $account = ProviderAccount::factory()->create(['provider_key' => 'fakeprovider']);

    ProviderCredential::factory()->create([...$credentialAttributes, 'provider_account_id' => $account->id]);

    app(AdapterRegistry::class)->register('fakeprovider', $adapter);

    return $account;
}

function runLifecycleSync(ProviderAccount $account, FakeProviderAdapter $adapter): SyncRun
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

function emptyCompleteInventory(?CarbonImmutable $observedAt = null): InventoryBatch
{
    return new InventoryBatch(BatchCompleteness::Complete, $observedAt ?? new CarbonImmutable, 'fake:inventory', []);
}

function emptyCompleteCosts(?CarbonImmutable $observedAt = null): CostFactBatch
{
    return new CostFactBatch(BatchCompleteness::Complete, $observedAt ?? new CarbonImmutable, 'fake:cost-facts', []);
}

function discoveredService(ProviderAccount $account, string $externalId = 'srv-1'): Service
{
    return Service::factory()->discovered($account)->create([
        'external_id' => $externalId,
        'first_seen_at' => now()->subDays(10),
        'last_seen_at' => now()->subDays(10),
    ]);
}

// ---------------------------------------------------------------------------
// Missing and inactive transitions
// ---------------------------------------------------------------------------

it('marks an omitted service missing, then inactive after three complete omissions', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: emptyCompleteInventory(),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);
    $service = discoveredService($account);

    runLifecycleSync($account, $adapter);
    expect($service->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Missing)
        ->and($service->fresh()->missing_complete_runs)->toBe(1);

    runLifecycleSync($account, $adapter);
    expect($service->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Missing)
        ->and($service->fresh()->missing_complete_runs)->toBe(2);

    runLifecycleSync($account, $adapter);
    expect($service->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Inactive)
        ->and($service->fresh()->missing_complete_runs)->toBe(3);
});

it('honors the configured inactive threshold', function () {
    config(['sync.inactive_after_complete_runs' => 2]);

    $adapter = new FakeProviderAdapter(
        inventoryBatch: emptyCompleteInventory(),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);
    $service = discoveredService($account);

    runLifecycleSync($account, $adapter);
    runLifecycleSync($account, $adapter);

    expect($service->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Inactive);
});

it('never marks a service missing from a partial batch', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: new InventoryBatch(BatchCompleteness::Partial, new CarbonImmutable, 'fake:inventory', []),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);
    $service = discoveredService($account);

    runLifecycleSync($account, $adapter);

    expect($service->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($service->fresh()->missing_complete_runs)->toBe(0);
});

it('never advances the missing counter on a failed run', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: emptyCompleteInventory(),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);
    $service = discoveredService($account);

    Sleep::fake();
    $adapter->validateExceptions = array_fill(
        0,
        4,
        new TransientProviderException('429'),
    );

    runLifecycleSync($account, $adapter);

    expect(SyncRun::query()->latest('id')->first()->status)->toBe(SyncStatus::Failed)
        ->and($service->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($service->fresh()->missing_complete_runs)->toBe(0);
});

it('restores a missing service when a partial batch observes it again', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: emptyCompleteInventory(),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);
    $service = discoveredService($account);

    // Two complete omissions: missing with a counter of two.
    runLifecycleSync($account, $adapter);
    runLifecycleSync($account, $adapter);
    expect($service->fresh()->missing_complete_runs)->toBe(2);

    $this->travel(1)->hour();

    // A partial batch sees the service again: presence is evidence.
    $adapter->inventoryBatch = new InventoryBatch(
        BatchCompleteness::Partial,
        new CarbonImmutable,
        'fake:inventory',
        [new InventoryItem('srv-1', 'compute', 'Service srv-1')],
    );
    runLifecycleSync($account, $adapter);

    $service = $service->fresh();
    expect($service->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($service->missing_complete_runs)->toBe(0)
        ->and($service->last_seen_at->equalTo(now()->startOfSecond()))->toBeTrue();
});

it('reactivates an inactive service that returns in a complete batch', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: emptyCompleteInventory(),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);
    $service = discoveredService($account);

    config(['sync.inactive_after_complete_runs' => 1]);
    runLifecycleSync($account, $adapter);
    expect($service->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Inactive);

    $this->travel(1)->hour();

    $adapter->inventoryBatch = new InventoryBatch(
        BatchCompleteness::Complete,
        new CarbonImmutable,
        'fake:inventory',
        [new InventoryItem('srv-1', 'compute', 'Service srv-1')],
    );
    runLifecycleSync($account, $adapter);

    $service = $service->fresh();
    expect($service->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($service->missing_complete_runs)->toBe(0);
});

it('keeps the last-seen timestamp of an omitted service untouched', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: emptyCompleteInventory(),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);
    $service = discoveredService($account);

    runLifecycleSync($account, $adapter);

    expect($service->fresh()->last_seen_at->equalTo(now()->subDays(10)->startOfSecond()))->toBeTrue();
});

it('never touches manual services', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: emptyCompleteInventory(),
        costFactBatch: emptyCompleteCosts(),
    );
    $account = lifecycleAccount($adapter);

    $manual = Service::factory()->create([
        'lifecycle_state' => ServiceLifecycle::Active,
        'missing_complete_runs' => 0,
    ]);

    runLifecycleSync($account, $adapter);

    expect($manual->fresh()->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($manual->fresh()->missing_complete_runs)->toBe(0);
});

// ---------------------------------------------------------------------------
// Charges: absence is not cancellation, except under a complete batch
// ---------------------------------------------------------------------------

it('ends an absent charge at the observation date when the batch is complete', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: new InventoryBatch(
            BatchCompleteness::Complete,
            new CarbonImmutable,
            'fake:inventory',
            [new InventoryItem('srv-1', 'compute', 'Service srv-1')],
        ),
        costFactBatch: new CostFactBatch(
            BatchCompleteness::Complete,
            new CarbonImmutable,
            'fake:cost-facts',
            [new CostFact(
                sourceRef: 'srv-1-monthly',
                serviceExternalIds: ['srv-1'],
                sourceKind: SourceKind::Subscription,
                chargeKind: ChargeKind::RecurringFixed,
                period: Period::Monthly,
                evidenceState: EvidenceState::Quote,
                amount: Money::ofMinor(1050, 'PLN'),
                validFrom: now()->startOfDay(),
                renewsAt: now()->addDays(30),
                autoRenew: true,
            )],
        ),
    );
    $account = lifecycleAccount($adapter);
    runLifecycleSync($account, $adapter);

    $this->travel(30)->days();

    // Thirty days later the provider reports nothing at all.
    $adapter->inventoryBatch = emptyCompleteInventory();
    $adapter->costFactBatch = emptyCompleteCosts();
    runLifecycleSync($account, $adapter);

    $item = CostItem::query()->sole();
    expect($item->amount_minor)->toBe(1050)
        ->and($item->valid_to?->equalTo(now()->subDay()->startOfDay()))->toBeTrue();

    // The renewal stays as historical evidence; nothing is deleted or zeroed.
    expect($item->amount_state->value)->toBe('known')
        ->and(Renewal::query()->count())->toBe(1);
});

it('never ends charges when the cost batch is only partial', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: new InventoryBatch(
            BatchCompleteness::Complete,
            new CarbonImmutable,
            'fake:inventory',
            [new InventoryItem('srv-1', 'compute', 'Service srv-1')],
        ),
        costFactBatch: new CostFactBatch(
            BatchCompleteness::Complete,
            new CarbonImmutable,
            'fake:cost-facts',
            [new CostFact(
                sourceRef: 'srv-1-monthly',
                serviceExternalIds: ['srv-1'],
                sourceKind: SourceKind::Subscription,
                chargeKind: ChargeKind::RecurringFixed,
                period: Period::Monthly,
                evidenceState: EvidenceState::Quote,
                amount: Money::ofMinor(1050, 'PLN'),
                validFrom: now()->startOfDay(),
            )],
        ),
    );
    $account = lifecycleAccount($adapter);
    runLifecycleSync($account, $adapter);

    $this->travel(30)->days();

    $adapter->costFactBatch = new CostFactBatch(
        BatchCompleteness::Partial,
        new CarbonImmutable,
        'fake:cost-facts',
        [],
        ['renewal quotes unavailable'],
    );
    runLifecycleSync($account, $adapter);

    expect(CostItem::query()->sole()->valid_to)->toBeNull()
        ->and(SyncRun::query()->latest('id')->first()->status)->toBe(SyncStatus::Partial);
});

// ---------------------------------------------------------------------------
// Fresh inventory must not hide old costs
// ---------------------------------------------------------------------------

it('keeps projecting the costs of an inactive service', function () {
    $adapter = new FakeProviderAdapter(
        capabilitySet: new CapabilitySet([ProviderCapability::Inventory]),
        inventoryBatch: new InventoryBatch(
            BatchCompleteness::Complete,
            new CarbonImmutable,
            'fake:inventory',
            [new InventoryItem('srv-1', 'compute', 'Service srv-1')],
        ),
    );
    $account = lifecycleAccount($adapter);
    runLifecycleSync($account, $adapter);

    // Manual cost evidence on the discovered service.
    $item = CostItem::factory()->invoiceActual()->create([
        'amount_minor' => 18742,
        'currency' => 'PLN',
    ]);
    $item->services()->attach(Service::query()->where('external_id', 'srv-1')->sole());

    $monthlyBefore = app(CostProjector::class)->project(new CarbonImmutable)
        ->forCurrency('PLN')?->monthlyMinor;

    // Three complete omissions make the service inactive; no cost phase
    // ever runs, so no cost evidence is touched.
    config(['sync.inactive_after_complete_runs' => 3]);
    $this->travel(1)->days();
    $adapter->inventoryBatch = emptyCompleteInventory();
    runLifecycleSync($account, $adapter);
    $this->travel(1)->days();
    runLifecycleSync($account, $adapter);
    $this->travel(1)->days();
    runLifecycleSync($account, $adapter);

    expect(Service::query()->where('external_id', 'srv-1')->sole()->lifecycle_state)
        ->toBe(ServiceLifecycle::Inactive);

    $projection = app(CostProjector::class)->project(new CarbonImmutable);

    expect($monthlyBefore)->toBe(18742)
        ->and($projection->forCurrency('PLN')?->monthlyMinor)->toBe(18742)
        ->and($projection->staleCount)->toBe(0);
});
