<?php

namespace Tests\Feature\Domain\Sync;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Dtos\CostFact;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\CredentialCheck;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Support\ValueObjects\Money;
use App\Domain\Sync\Actions\RequestSync;
use App\Domain\Sync\Enums\SyncStage;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Jobs\SyncProviderAccount;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Fakes\FakeProviderAdapter;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->freezeTime();
    $this->app->forgetInstance(AdapterRegistry::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeAccount(FakeProviderAdapter $adapter, array $attributes = [], array $credentialAttributes = [], ?string $registerAs = 'fakeprovider'): ProviderAccount
{
    $account = ProviderAccount::factory()
        ->create([...$attributes, 'provider_key' => $attributes['provider_key'] ?? 'fakeprovider']);

    ProviderCredential::factory()
        ->create([...$credentialAttributes, 'provider_account_id' => $account->id]);

    if ($registerAs !== null) {
        app(AdapterRegistry::class)->register($registerAs, $adapter);
    }

    return $account;
}

function queuedRun(ProviderAccount $account): SyncRun
{
    return SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
        'started_at' => null,
        'finished_at' => null,
    ]);
}

function runSync(ProviderAccount $account, FakeProviderAdapter $adapter): SyncRun
{
    $run = queuedRun($account);

    return app(SyncOrchestrator::class)->run($run);
}

function inventoryItem(string $externalId, string $category = 'compute'): InventoryItem
{
    return new InventoryItem($externalId, $category, "Service {$externalId}");
}

function inventoryBatch(array $items = [], BatchCompleteness $completeness = BatchCompleteness::Complete, ?CarbonImmutable $observedAt = null, array $warnings = []): InventoryBatch
{
    return new InventoryBatch(
        $completeness,
        $observedAt ?? new CarbonImmutable,
        'fake:inventory',
        $items,
        $warnings,
    );
}

function costFact(string $sourceRef, array $covers, ?Money $amount = null, ?CarbonImmutable $validFrom = null): CostFact
{
    return new CostFact(
        sourceRef: $sourceRef,
        serviceExternalIds: $covers,
        sourceKind: SourceKind::Subscription,
        chargeKind: ChargeKind::RecurringFixed,
        period: Period::Monthly,
        evidenceState: EvidenceState::Quote,
        amount: $amount,
        validFrom: $validFrom ?? (new CarbonImmutable)->startOfDay(),
        taxBasis: TaxBasis::Unknown,
        renewsAt: (new CarbonImmutable)->addDays(30),
        autoRenew: true,
    );
}

function costBatch(array $facts = [], BatchCompleteness $completeness = BatchCompleteness::Complete, ?CarbonImmutable $observedAt = null, array $capabilityCompleteness = [], array $warnings = [], array $reportedCapabilities = []): CostFactBatch
{
    return new CostFactBatch(
        $completeness,
        $observedAt ?? new CarbonImmutable,
        'fake:cost-facts',
        $facts,
        $warnings,
        $capabilityCompleteness,
        $reportedCapabilities,
    );
}

function emptyInventory(): InventoryBatch
{
    return new InventoryBatch(BatchCompleteness::Complete, new CarbonImmutable, 'fake:inventory', []);
}

function emptyCostFacts(): CostFactBatch
{
    return new CostFactBatch(BatchCompleteness::Complete, new CarbonImmutable, 'fake:cost-facts', []);
}

// ---------------------------------------------------------------------------
// Happy path and idempotency
// ---------------------------------------------------------------------------

it('persists a complete batch and finishes the run as succeeded', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([
            inventoryItem('srv-1', 'compute'),
            inventoryItem('dom-1', 'domain'),
        ]),
        costFactBatch: costBatch([
            costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN')),
        ]),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Succeeded)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->counts)->toBe([
            'inventory' => ['seen' => 2, 'created' => 2, 'updated' => 0],
            'cost_facts' => ['seen' => 1, 'created' => 1, 'superseded' => 0, 'updated' => 0, 'renewals' => 1, 'ended' => 0],
        ]);

    // Services carry provider identity and seen timestamps on creation.
    $service = Service::query()->where('external_id', 'srv-1')->sole();
    expect($service->provider_account_id)->toBe($account->id)
        ->and($service->category)->toBe('compute')
        ->and($service->lifecycle_state)->toBe(ServiceLifecycle::Active)
        ->and($service->first_seen_at->equalTo(now()->startOfSecond()))->toBeTrue()
        ->and($service->last_seen_at->equalTo(now()->startOfSecond()))->toBeTrue();

    // The cost fact lands under the provider identity scheme with coverage.
    $item = CostItem::query()->sole();
    expect($item->logical_charge_key)->toBe("fakeprovider:account:{$account->id}:charge:srv-1-monthly")
        ->and($item->identity_key)->toBe($item->logical_charge_key.':from:'.now()->startOfDay()->format('Y-m-d'))
        ->and($item->amount_minor)->toBe(1050)
        ->and($item->currency)->toBe('PLN')
        ->and($item->amount_state->value)->toBe('known')
        ->and($item->observed_at->equalTo(now()->startOfSecond()))->toBeTrue()
        ->and($item->services->pluck('external_id')->toArray())->toBe(['srv-1']);

    $renewal = Renewal::query()->sole();
    expect($renewal->cost_item_id)->toBe($item->id)
        ->and($renewal->auto_renew)->toBeTrue();

    // Account and capability bookkeeping.
    expect($account->fresh()->last_success_at?->equalTo(now()->startOfSecond()))->toBeTrue();

    $inventoryState = ProviderCapabilityState::query()
        ->where('capability_key', 'inventory')->sole();
    expect($inventoryState->supported)->toBeTrue()
        ->and($inventoryState->healthy)->toBeTrue()
        ->and($inventoryState->last_attempt_at)->not->toBeNull()
        ->and($inventoryState->last_success_at)->not->toBeNull()
        ->and($inventoryState->last_observed_at)->not->toBeNull();

    $quotesState = ProviderCapabilityState::query()
        ->where('capability_key', 'renewal_quotes')->sole();
    expect($quotesState->supported)->toBeTrue()->and($quotesState->healthy)->toBeTrue();

    // A capability outside the set is explicitly unsupported, never faked.
    $usageState = ProviderCapabilityState::query()
        ->where('capability_key', 'usage')->sole();
    expect($usageState->supported)->toBeFalse()
        ->and($usageState->last_attempt_at)->toBeNull();
});

it('re-syncs identical data without creating duplicates', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);

    $first = runSync($account, $adapter)->fresh();
    $serviceUpdatedAt = Service::query()->sole()->updated_at;

    $this->travel(5)->minutes();

    $second = runSync($account, $adapter)->fresh();

    expect($second->status)->toBe(SyncStatus::Succeeded)
        ->and(Service::query()->count())->toBe(1)
        ->and(CostItem::query()->count())->toBe(1)
        // Nothing changed, so nothing was written: persistence skips the
        // identical row and lifecycle writes the same values the fake's
        // frozen batch carries.
        ->and(Service::query()->sole()->updated_at->equalTo($serviceUpdatedAt))->toBeTrue()
        ->and($second->counts['inventory'])->toBe(['seen' => 1, 'created' => 0, 'updated' => 0])
        ->and($second->counts['cost_facts'])->toBe(['seen' => 1, 'created' => 0, 'superseded' => 0, 'updated' => 0, 'renewals' => 1, 'ended' => 0]);

    expect($first->id)->not->toBe($second->id);
});

it('supersedes the open fact when the provider reports a new price', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hours();

    $tomorrow = now()->addDay()->startOfDay();
    $adapter->costFactBatch = costBatch([
        costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1200, 'PLN'), $tomorrow),
    ]);

    $run = runSync($account, $adapter)->fresh();

    $items = CostItem::query()->orderBy('id')->get();
    expect($run->counts['cost_facts'])->toBe(['seen' => 1, 'created' => 1, 'superseded' => 1, 'updated' => 0, 'renewals' => 1, 'ended' => 0])
        ->and($items)->toHaveCount(2);

    $closed = $items->first();
    expect($closed->amount_minor)->toBe(1050)
        ->and($closed->valid_to->equalTo(now()->startOfDay()))->toBeTrue();

    $open = $items->last();
    expect($open->amount_minor)->toBe(1200)
        ->and($open->valid_to)->toBeNull()
        ->and($open->identity_key)->toBe($open->logical_charge_key.':from:'.$tomorrow->format('Y-m-d'))
        // History keeps coverage on both versions.
        ->and($open->services->pluck('external_id')->toArray())->toBe(['srv-1']);

    // Renewals always follow the newest version.
    $renewal = Renewal::query()->sole();
    expect($renewal->cost_item_id)->toBe($open->id);
});

it('upgrades evidence in place when only the evidence state improves', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    $fact = costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'));
    $adapter->costFactBatch = costBatch([
        new CostFact(
            sourceRef: $fact->sourceRef,
            serviceExternalIds: $fact->serviceExternalIds,
            sourceKind: SourceKind::Invoice,
            chargeKind: ChargeKind::RecurringFixed,
            period: Period::Monthly,
            evidenceState: EvidenceState::Actual,
            amount: Money::ofMinor(1050, 'PLN'),
            validFrom: $fact->validFrom,
            renewsAt: null,
        ),
    ]);

    $run = runSync($account, $adapter)->fresh();

    expect(CostItem::query()->count())->toBe(1)
        ->and($run->counts['cost_facts'])->toBe(['seen' => 1, 'created' => 0, 'superseded' => 0, 'updated' => 1, 'renewals' => 0, 'ended' => 0]);

    $item = CostItem::query()->sole();
    expect($item->evidence_state)->toBe(EvidenceState::Actual)
        ->and($item->source_kind)->toBe(SourceKind::Invoice)
        ->and($item->valid_to)->toBeNull()
        // No new renewal date: the existing renewal is left untouched.
        ->and(Renewal::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Degraded runs keep the last good data
// ---------------------------------------------------------------------------

it('records a partial batch as partial without losing what arrived', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch(
            [inventoryItem('srv-1')],
            BatchCompleteness::Partial,
        ),
        costFactBatch: costBatch([
            costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN')),
        ]),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Partial)
        ->and(Service::query()->count())->toBe(1)
        ->and(CostItem::query()->count())->toBe(1)
        ->and($account->fresh()->last_success_at)->not->toBeNull();

    // A partial inventory marks the capability stale: it answered, but not fully.
    $inventoryState = ProviderCapabilityState::query()
        ->where('capability_key', 'inventory')->sole();
    expect($inventoryState->healthy)->toBeFalse()
        ->and($inventoryState->last_observed_at)->not->toBeNull();
});

it('keeps the last good data when the provider keeps failing transiently', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $goodSuccessAt = $account->fresh()->last_success_at;
    $goodCapability = ProviderCapabilityState::query()->where('capability_key', 'inventory')->sole();

    $this->travel(1)->hour();

    Sleep::fake();
    $adapter->inventoryExceptions = array_fill(0, 4, new TransientProviderException('429 too many requests'));
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        // Last good data survives untouched.
        ->and(Service::query()->count())->toBe(1)
        ->and(CostItem::query()->sole()->amount_minor)->toBe(1050)
        ->and($account->fresh()->last_success_at->equalTo($goodSuccessAt))->toBeTrue()
        // 1 call from the earlier good sync + the full retry budget of 4.
        ->and($adapter->inventoryCalls)->toBe(5)
        ->and($run->summary['error'])->toBe('TransientProviderException: 429 too many requests (after 4 attempts)');

    $stale = ProviderCapabilityState::query()->where('capability_key', 'inventory')->sole();
    expect($stale->healthy)->toBeFalse()
        ->and($stale->last_success_at->equalTo($goodCapability->last_success_at))->toBeTrue();
});

it('never writes cost data when the inventory phase fails', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    Sleep::fake();
    $adapter->inventoryExceptions = array_fill(0, 4, new TransientProviderException('500 whoops'));
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->counts)->toBeNull()
        // The only cost call is from the earlier good sync; the failed
        // run never reached the cost phase.
        ->and($adapter->costCalls)->toBe(1)
        ->and(CostItem::query()->sole()->amount_minor)->toBe(1050);
});

it('records a failed cost phase as partial while keeping fresh inventory', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);

    $this->travel(1)->hour();

    Sleep::fake();
    $adapter->costExceptions = array_fill(0, 4, new TransientProviderException('timeout'));
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Partial)
        ->and(Service::query()->count())->toBe(1)
        ->and(CostItem::query()->count())->toBe(0)
        ->and($run->counts['cost_facts'] ?? null)->toBeNull()
        ->and($run->summary['warnings'])->toBe(['TransientProviderException: timeout (after 4 attempts)']);

    $quotesState = ProviderCapabilityState::query()
        ->where('capability_key', 'renewal_quotes')->sole();
    expect($quotesState->healthy)->toBeFalse()
        ->and($quotesState->last_attempt_at)->not->toBeNull()
        ->and($quotesState->last_observed_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Credentials
// ---------------------------------------------------------------------------

it('fails fast on invalid credentials without fetching anything', function () {
    $adapter = new FakeProviderAdapter(
        credentialCheck: CredentialCheck::invalid('provider rejected the credentials'),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($adapter->validateCalls)->toBe(1)
        ->and($adapter->inventoryCalls)->toBe(0)
        ->and($run->summary['error'])->toBe('provider rejected the credentials');

    $credential = $account->credentials()->sole();
    expect($credential->verified_at)->toBeNull();
});

it('validates credentials only when they are unverified or changed', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    expect($adapter->validateCalls)->toBe(1);

    $credential = $account->credentials()->sole();
    expect($credential->verified_at)->not->toBeNull()
        ->and($credential->fingerprint)->toBeString();

    // Same payload: no re-validation.
    runSync($account, $adapter);
    expect($adapter->validateCalls)->toBe(1);

    // Changed payload: validation runs again.
    $credential->payload = ['key' => 'other-key-value-1234', 'secret' => 'other-secret-1234'];
    $credential->save();

    runSync($account, $adapter);
    expect($adapter->validateCalls)->toBe(2);
});

it('redacts credential material from run summaries', function () {
    $payload = ['key' => 'hk-1234567890abcdef', 'secret' => 'supersecretvalue-9999'];
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch(
            [inventoryItem('srv-1')],
            warnings: ["provider said {$payload['secret']} was wrong"],
        ),
        costFactBatch: costBatch(
            [costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))],
            BatchCompleteness::Partial,
            warnings: ["rejected key {$payload['key']}"],
        ),
    );
    $account = makeAccount($adapter, credentialAttributes: ['payload' => $payload]);
    $run = runSync($account, $adapter)->fresh();

    $encoded = (string) json_encode($run->summary);
    expect($encoded)->not->toContain($payload['secret'])
        ->not->toContain($payload['key'])
        ->toContain('[redacted]');
});

// ---------------------------------------------------------------------------
// Batches that violate invariants
// ---------------------------------------------------------------------------

it('fails the run and persists nothing when a batch is invalid', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1', 'knickknacks')]),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and(Service::query()->count())->toBe(0)
        ->and($run->summary['error'])->toContain('non-canonical category');
});

it('records an invalid cost batch as partial while keeping the inventory', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('mystery-charge', ['srv-404'], Money::ofMinor(100, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Partial)
        ->and(CostItem::query()->count())->toBe(0)
        ->and(Service::query()->count())->toBe(1)
        ->and($run->summary['warnings'][0])->toContain('not part of this run');
});

// ---------------------------------------------------------------------------
// Accounts and runs
// ---------------------------------------------------------------------------

it('cancels the run for a disabled account without calling the provider', function () {
    $adapter = new FakeProviderAdapter;
    $account = makeAccount($adapter, ['enabled' => false]);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Cancelled)
        ->and($adapter->validateCalls)->toBe(0);
});

it('fails the run when no adapter is registered for the provider', function () {
    $adapter = new FakeProviderAdapter;
    $account = makeAccount($adapter, ['provider_key' => 'ghost']);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->summary['error'])->toContain('ghost');
});

it('never starts a parallel run through the request action', function () {
    Queue::fake();

    $adapter = new FakeProviderAdapter;
    $account = makeAccount($adapter);

    $first = app(RequestSync::class)->request($account);

    expect($first)->not->toBeNull()
        ->and(SyncRun::query()->count())->toBe(1)
        ->and(Queue::pushed(SyncProviderAccount::class))->toHaveCount(1);

    // The refused insert leaves a Postgres transaction aborted (a
    // swallowed exception never triggers the savepoint rollback), so
    // this must be the last thing the test does with the database.
    expect(app(RequestSync::class)->request($account))->toBeNull();
});

it('lets the database reject a second active run for the account', function () {
    $account = ProviderAccount::factory()->create();

    SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => SyncStatus::Queued,
    ]);

    expect(fn () => DB::transaction(fn () => SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => SyncStatus::Running,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('abandons a stale active run so future syncs are not blocked', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create();

    $stale = SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => SyncStatus::Queued,
    ]);
    $stale->forceFill(['created_at' => now()->subSeconds((int) config('sync.stale_run_after_seconds'))->subMinute()])->save();

    $run = app(RequestSync::class)->request($account);

    expect($run)->not->toBeNull()
        ->and($stale->fresh()->status)->toBe(SyncStatus::Failed)
        ->and($stale->fresh()->summary['error'])->toBe('abandoned before completion');
});

it('fails the run when the account lock is already held', function () {
    $adapter = new FakeProviderAdapter;
    $account = makeAccount($adapter);

    Cache::lock("sync:account:{$account->id}:full", 60)->acquire();

    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->summary['error'])->toBe('account lock is held by another sync')
        ->and($adapter->validateCalls)->toBe(0);
});

it('runs the orchestrator when the queued job is handled', function () {
    Queue::fake();

    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
    );
    $account = makeAccount($adapter);

    $run = app(RequestSync::class)->request($account);
    expect($run)->not->toBeNull();

    Queue::assertPushed(SyncProviderAccount::class);

    $job = new SyncProviderAccount($run->id);
    $job->handle(app(SyncOrchestrator::class));

    expect($run->fresh()->status)->toBe(SyncStatus::Succeeded);
});

it('marks an unexpectedly failed job as a failed run', function () {
    $account = ProviderAccount::factory()->create();
    $run = queuedRun($account);

    (new SyncProviderAccount($run->id))->failed(new RuntimeException('worker died'));

    $run = $run->fresh();
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->summary['error'])->toContain('RuntimeException');
});

// ---------------------------------------------------------------------------
// Review hardening: recovery paths, per-capability freshness, money edges
// ---------------------------------------------------------------------------

it('abandons a stale running run so a crashed worker cannot block the account', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create();

    $stale = SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => SyncStatus::Running,
        'started_at' => now()->subMinute(),
        'finished_at' => null,
    ]);
    $stale->forceFill(['created_at' => now()->subSeconds((int) config('sync.stale_run_after_seconds'))->subMinute()])->save();

    $run = app(RequestSync::class)->request($account);

    expect($run)->not->toBeNull()
        ->and($stale->fresh()->status)->toBe(SyncStatus::Failed)
        ->and($stale->fresh()->summary['error'])->toBe('abandoned before completion');
});

it('does not overwrite a finished run when the job fails late', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    (new SyncProviderAccount($run->id))->failed(new RuntimeException('late crash'));

    $finished = $run->fresh();
    expect($finished->status)->toBe(SyncStatus::Succeeded)
        ->and($finished->counts)->not->toBeNull()
        ->and($finished->summary)->toBeNull();
});

it('keeps per-capability freshness when one cost capability fails', function () {
    $adapter = new FakeProviderAdapter(
        capabilitySet: new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::Subscriptions,
            ProviderCapability::Usage,
        ]),
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch(
            [costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))],
            BatchCompleteness::Complete,
            capabilityCompleteness: [
                'subscriptions' => BatchCompleteness::Complete,
                'usage' => BatchCompleteness::Failed,
            ],
        ),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Partial)
        ->and(CostItem::query()->count())->toBe(1);

    $subscriptions = ProviderCapabilityState::query()->where('capability_key', 'subscriptions')->sole();
    $usage = ProviderCapabilityState::query()->where('capability_key', 'usage')->sole();

    expect($subscriptions->healthy)->toBeTrue()
        ->and($subscriptions->last_observed_at)->not->toBeNull()
        // A failed sub-capability never reads fresh, and an overall
        // complete batch must not end charges on its behalf.
        ->and($usage->healthy)->toBeFalse()
        ->and($usage->last_observed_at)->toBeNull();
});

it('treats a declared but unsupported capability as non-degrading and unhealthy', function () {
    $adapter = new FakeProviderAdapter(
        capabilitySet: new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::Subscriptions,
            ProviderCapability::Usage,
        ]),
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch(
            [costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))],
            BatchCompleteness::Complete,
            capabilityCompleteness: [
                'subscriptions' => BatchCompleteness::Complete,
                'usage' => BatchCompleteness::Unsupported,
            ],
        ),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    $usage = ProviderCapabilityState::query()->where('capability_key', 'usage')->sole();

    expect($run->status)->toBe(SyncStatus::Succeeded)
        ->and($usage->supported)->toBeTrue()
        ->and($usage->healthy)->toBeFalse()
        ->and($usage->last_observed_at)->toBeNull();
});

it('rejects a batch whose completeness contradicts its content', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')], BatchCompleteness::Failed),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and(Service::query()->count())->toBe(0)
        ->and($run->summary['error'])->toContain('but carries items');
});

it('records a cost batch with duplicate source references as partial', function () {
    $fact = costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'));
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([$fact, $fact]),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Partial)
        ->and(CostItem::query()->count())->toBe(0)
        ->and(Service::query()->count())->toBe(1)
        ->and($run->summary['warnings'][0])->toContain('duplicate source reference');
});

it('stores an unknown amount when the provider reports no price', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], null)]),
    );
    $account = makeAccount($adapter);
    $run = runSync($account, $adapter)->fresh();

    $item = CostItem::query()->sole();
    expect($run->status)->toBe(SyncStatus::Succeeded)
        ->and($item->amount_state->value)->toBe('unknown')
        ->and($item->amount_minor)->toBeNull()
        ->and($item->currency)->toBeNull();
});

it('supersedes a known price when the provider stops reporting an amount', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    $adapter->costFactBatch = costBatch([costFact('srv-1-monthly', ['srv-1'], null)]);
    runSync($account, $adapter);

    $items = CostItem::query()->orderBy('id')->get();
    expect($items)->toHaveCount(2);

    $closed = $items->first();
    expect($closed->amount_minor)->toBe(1050)
        ->and($closed->valid_to)->not->toBeNull();

    $open = $items->last();
    expect($open->amount_state->value)->toBe('unknown')
        ->and($open->amount_minor)->toBeNull()
        ->and($open->valid_to)->toBeNull();
});

it('persists what a partial cost batch delivered without ending anything', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1'), inventoryItem('srv-2', 'storage')]),
        costFactBatch: costBatch([
            costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN')),
            costFact('srv-2-monthly', ['srv-2'], Money::ofMinor(500, 'PLN')),
        ]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    // The partial batch only saw the first charge again.
    $adapter->costFactBatch = costBatch(
        [costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))],
        BatchCompleteness::Partial,
    );
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Partial)
        ->and(CostItem::query()->count())->toBe(2)
        ->and($run->counts['cost_facts']['updated'])->toBe(1)
        ->and($run->counts['cost_facts'])->not->toHaveKey('ended');

    $absent = CostItem::query()->where('logical_charge_key', 'like', '%srv-2-monthly')->sole();
    expect($absent->valid_to)->toBeNull();
});

it('never ends another account\'s charges when a complete batch is empty', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $accountA = makeAccount($adapter);
    runSync($accountA, $adapter);

    // A second account of the same provider syncs an empty complete batch.
    $accountB = ProviderAccount::factory()->create(['provider_key' => 'fakeprovider']);
    ProviderCredential::factory()->create(['provider_account_id' => $accountB->id]);
    $adapter->inventoryBatch = emptyInventory();
    $adapter->costFactBatch = emptyCostFacts();
    runSync($accountB, $adapter);

    expect(CostItem::query()->sole()->valid_to)->toBeNull();
});

it('creates a new version when an ended charge reappears', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(10)->days();
    $adapter->inventoryBatch = emptyInventory();
    $adapter->costFactBatch = emptyCostFacts();
    runSync($account, $adapter);

    $this->travel(10)->days();
    $tomorrow = now()->addDay()->startOfDay();
    $adapter->inventoryBatch = inventoryBatch([inventoryItem('srv-1')]);
    $adapter->costFactBatch = costBatch([
        new CostFact(
            sourceRef: 'srv-1-monthly',
            serviceExternalIds: ['srv-1'],
            sourceKind: SourceKind::Subscription,
            chargeKind: ChargeKind::RecurringFixed,
            period: Period::Monthly,
            evidenceState: EvidenceState::Quote,
            amount: Money::ofMinor(1200, 'PLN'),
            validFrom: $tomorrow,
        ),
    ]);
    $run = runSync($account, $adapter)->fresh();

    $items = CostItem::query()->orderBy('id')->get();
    expect($items)->toHaveCount(2);

    $ended = $items->first();
    expect($ended->amount_minor)->toBe(1050)
        ->and($ended->valid_to)->not->toBeNull();

    $open = $items->last();
    expect($open->amount_minor)->toBe(1200)
        ->and($open->valid_to)->toBeNull()
        ->and($open->identity_key)->toBe($open->logical_charge_key.':from:'.$tomorrow->format('Y-m-d'));

    // The middle run recorded the ending.
    $middle = SyncRun::query()->orderBy('id')->get()->get(1);
    expect($middle->counts['cost_facts']['ended'])->toBe(1);
});

it('ends charges on a complete reporting capability even while another declared capability stays partial without facts', function () {
    $fact = new CostFact(
        sourceRef: 'srv-1-monthly',
        serviceExternalIds: ['srv-1'],
        sourceKind: SourceKind::Subscription,
        chargeKind: ChargeKind::RecurringFixed,
        period: Period::Monthly,
        evidenceState: EvidenceState::Quote,
        amount: Money::ofMinor(1050, 'PLN'),
        validFrom: (new CarbonImmutable)->startOfDay(),
        renewsAt: (new CarbonImmutable)->addDays(30),
        autoRenew: true,
    );
    $adapter = new FakeProviderAdapter(
        capabilitySet: new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::Subscriptions,
            ProviderCapability::Usage,
        ]),
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([$fact], capabilityCompleteness: [
            ProviderCapability::Subscriptions->value => BatchCompleteness::Complete,
            ProviderCapability::Usage->value => BatchCompleteness::Partial,
        ], reportedCapabilities: [ProviderCapability::Subscriptions]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    // Usage is permanently partial and reports no facts at all; the
    // complete subscription observation still proves the charge ended.
    $adapter->costFactBatch = costBatch([], capabilityCompleteness: [
        ProviderCapability::Subscriptions->value => BatchCompleteness::Complete,
        ProviderCapability::Usage->value => BatchCompleteness::Partial,
    ], reportedCapabilities: [ProviderCapability::Subscriptions]);
    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Partial)
        ->and($run->counts['cost_facts']['ended'])->toBe(1)
        ->and(CostItem::query()->whereNull('valid_to')->count())->toBe(0);
});

it('ends nothing while the capability that reported facts is itself partial', function () {
    $fact = new CostFact(
        sourceRef: 'srv-1-monthly',
        serviceExternalIds: ['srv-1'],
        sourceKind: SourceKind::Subscription,
        chargeKind: ChargeKind::RecurringFixed,
        period: Period::Monthly,
        evidenceState: EvidenceState::Quote,
        amount: Money::ofMinor(1050, 'PLN'),
        validFrom: (new CarbonImmutable)->startOfDay(),
    );
    $adapter = new FakeProviderAdapter(
        capabilitySet: new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::Subscriptions,
        ]),
        inventoryBatch: inventoryBatch([inventoryItem('srv-1'), inventoryItem('srv-2', 'storage')]),
        costFactBatch: costBatch([$fact], capabilityCompleteness: [
            ProviderCapability::Subscriptions->value => BatchCompleteness::Partial,
        ], reportedCapabilities: [ProviderCapability::Subscriptions]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    // A partial subscription observation no longer reports the charge —
    // but its absence is not cancellation evidence.
    $adapter->costFactBatch = costBatch([], capabilityCompleteness: [
        ProviderCapability::Subscriptions->value => BatchCompleteness::Partial,
    ], reportedCapabilities: [ProviderCapability::Subscriptions]);
    $run = runSync($account, $adapter)->fresh();

    expect($run->counts['cost_facts'])->not->toHaveKey('ended')
        ->and(CostItem::query()->whereNull('valid_to')->count())->toBe(1);
});

it('keeps the conservative ending rule for batches with untagged facts', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch(
            [costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))],
            capabilityCompleteness: [ProviderCapability::RenewalQuotes->value => BatchCompleteness::Partial],
        ),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    $adapter->costFactBatch = costBatch(
        [],
        capabilityCompleteness: [ProviderCapability::RenewalQuotes->value => BatchCompleteness::Partial],
    );
    $run = runSync($account, $adapter)->fresh();

    // Untagged facts cannot be attributed to a capability, so every
    // declared cost capability must be complete before anything ends.
    expect($run->counts['cost_facts'])->not->toHaveKey('ended')
        ->and(CostItem::query()->whereNull('valid_to')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Console entry point
// ---------------------------------------------------------------------------

it('queues syncs for every enabled account from the console', function () {
    Queue::fake();
    $adapter = new FakeProviderAdapter;

    $enabledA = makeAccount($adapter, ['display_name' => 'Alpha']);
    $enabledB = makeAccount($adapter, ['display_name' => 'Beta', 'provider_key' => 'fakeprovider-b'], registerAs: null);
    ProviderAccount::factory()->create(['enabled' => false, 'display_name' => 'Gamma']);

    artisan('lafiel:sync')->assertSuccessful();

    expect(SyncRun::query()->whereIn('provider_account_id', [$enabledA->id, $enabledB->id])->count())->toBe(2)
        ->and(SyncRun::query()->count())->toBe(2);

    Queue::assertPushed(SyncProviderAccount::class, 2);
});

it('queues a sync for one account only', function () {
    Queue::fake();
    $adapter = new FakeProviderAdapter;

    $target = makeAccount($adapter);
    makeAccount($adapter, registerAs: null);

    artisan('lafiel:sync', ['--account' => [$target->id]])->assertSuccessful();

    expect(SyncRun::query()->count())->toBe(1)
        ->and(SyncRun::query()->sole()->provider_account_id)->toBe($target->id);
});

it('refuses an unknown trigger', function () {
    artisan('lafiel:sync', ['--trigger' => 'chaos'])->assertExitCode(1)
        ->expectsOutputToContain('Unknown trigger');
});

it('reports an already active sync instead of queueing another', function () {
    Queue::fake();
    $adapter = new FakeProviderAdapter;

    $account = makeAccount($adapter);

    app(RequestSync::class)->request($account);
    expect(SyncRun::query()->count())->toBe(1);

    // The refused insert inside the command leaves a Postgres
    // transaction aborted, so no query may follow it in this test.
    artisan('lafiel:sync')->assertSuccessful()
        ->expectsOutputToContain('already syncing');
});

it('reports the dispatch failure cause from the sync command', function () {
    makeAccount(new FakeProviderAdapter);

    Bus::shouldReceive('dispatch')
        ->andThrow(new RuntimeException('queue connection refused'));

    artisan('lafiel:sync')
        ->expectsOutputToContain('could not queue the sync job: RuntimeException')
        ->assertFailed();
});

it('fails the run and clears verification when the provider rejects credentials mid-cost-phase', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $credential = $account->credentials()->latest('id')->first();
    expect($credential->refresh()->verified_at)->not->toBeNull();

    $this->travel(1)->hour();

    // A verified credential skips validation, so the rejection must
    // surface mid-run, during the cost-facts fetch.
    $adapter->costExceptions = [new InvalidCredentialsException('rejected for [cost] (HTTP 401).')];

    $run = runSync($account, $adapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->stage)->toBe(SyncStage::Costs)
        ->and($credential->refresh()->verified_at)->toBeNull();
});

it('keeps a stored note when a later observation carries none', function () {
    $fact = costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'));
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([new CostFact(
            sourceRef: $fact->sourceRef,
            serviceExternalIds: $fact->serviceExternalIds,
            sourceKind: $fact->sourceKind,
            chargeKind: $fact->chargeKind,
            period: $fact->period,
            evidenceState: $fact->evidenceState,
            amount: $fact->amount,
            validFrom: $fact->validFrom,
            renewsAt: $fact->renewsAt,
            autoRenew: $fact->autoRenew,
            notes: 'Rate plan: Bundle',
        )]),
    );
    $account = makeAccount($adapter);
    runSync($account, $adapter);

    $this->travel(1)->hour();

    // The next observation omits the note entirely — that is not the
    // news that the note is gone.
    $adapter->costFactBatch = costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]);
    runSync($account, $adapter);

    expect(CostItem::query()->sole()->notes)->toBe('Rate plan: Bundle');
});

// ---------------------------------------------------------------------------
// Stage tracking and failure causes (sync activity view)
// ---------------------------------------------------------------------------

it('records the stage a run reached', function () {
    $adapter = new FakeProviderAdapter(
        inventoryBatch: inventoryBatch([inventoryItem('srv-1')]),
        costFactBatch: costBatch([costFact('srv-1-monthly', ['srv-1'], Money::ofMinor(1050, 'PLN'))]),
    );
    $account = makeAccount($adapter);

    // A run that completes ends on the persisting stage.
    expect(runSync($account, $adapter)->fresh()->stage)->toBe(SyncStage::Persisting);

    // A run that dies on inventory keeps the inventory stage.
    Sleep::fake();
    $this->travel(1)->hour();
    $adapter->inventoryExceptions = array_fill(0, 4, new TransientProviderException('429 too many requests'));

    $run = runSync($account, $adapter)->fresh();
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->stage)->toBe(SyncStage::Inventory);

    // A run rejected at credential validation keeps the credentials stage.
    $rejected = makeAccount(new FakeProviderAdapter(
        credentialCheck: CredentialCheck::invalid('provider rejected the credentials'),
    ), ['provider_key' => 'rejected'], registerAs: 'rejected');
    $run = runSync($rejected, new FakeProviderAdapter)->fresh();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->stage)->toBe(SyncStage::Credentials);
});

it('redacts the failure cause stored as the run error', function () {
    $payload = ['key' => 'hk-1234567890abcdef', 'secret' => 'supersecretvalue-9999'];
    Sleep::fake();
    $adapter = new FakeProviderAdapter;
    $adapter->inventoryExceptions = array_fill(0, 4, new TransientProviderException("429 leaked {$payload['secret']}"));
    $account = makeAccount($adapter, credentialAttributes: ['payload' => $payload]);

    $run = runSync($account, $adapter)->fresh();

    $encoded = (string) json_encode($run->summary);
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->summary['error'])->toContain('[redacted]')
        ->and($encoded)->not->toContain($payload['secret']);
});
