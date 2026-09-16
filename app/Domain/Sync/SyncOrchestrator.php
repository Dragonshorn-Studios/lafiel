<?php

namespace App\Domain\Sync;

use App\Domain\History\TakeSnapshot;
use App\Domain\Inventory\Lifecycle\ApplyInventoryLifecycle;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\CapabilityOutcome;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\UnsupportedProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\RecordCapabilityStates;
use App\Domain\Support\Redaction\Redactor;
use App\Domain\Sync\Enums\SyncStage;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Persistence\EndAbsentCostFacts;
use App\Domain\Sync\Persistence\PersistCostFacts;
use App\Domain\Sync\Persistence\PersistInventoryBatch;
use App\Domain\Sync\Retry\RetryPolicy;
use App\Domain\Sync\Validation\ValidateBatches;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The sync algorithm for one provider account, per docs/architecture.md:
 * lock, claim the run, validate credentials only when needed, fetch
 * outside a transaction, validate batch invariants, persist
 * idempotently in a short transaction, apply lifecycle transitions —
 * missing/inactive only after a complete inventory, presence on any
 * usable batch — and finish with counts and a sanitized summary:
 * warnings, plus the primary error for failures.
 */
final class SyncOrchestrator
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly RetryPolicy $retry,
        private readonly ValidateBatches $validation,
        private readonly PersistInventoryBatch $persistInventory,
        private readonly PersistCostFacts $persistCostFacts,
        private readonly EndAbsentCostFacts $endAbsentCostFacts,
        private readonly TakeSnapshot $takeSnapshot,
        private readonly ApplyInventoryLifecycle $applyLifecycle,
        private readonly RecordCapabilityStates $recordCapabilities,
        private readonly ReconcileStaleRuns $reconcile,
    ) {}

    public function run(SyncRun $run): SyncRun
    {
        $run->load('providerAccount');
        $account = $run->providerAccount;

        if (! $account->enabled) {
            return $this->finish($run, SyncStatus::Cancelled, [], ['account is disabled']);
        }

        $lock = Cache::lock(
            sprintf('sync:account:%d:full', $account->id),
            (int) config('sync.lock_ttl_seconds', 600),
        );

        // One sync per account. The active-run index guarantees this run
        // is the account's only live run, so a held lock always belongs
        // to a stale worker — fail fast and let the TTL recover it.
        if (! $lock->get()) {
            return $this->fail($run, 'account lock is held by another sync');
        }

        try {
            return $this->execute($run, $account);
        } finally {
            $lock->release();
        }
    }

    private function execute(SyncRun $run, ProviderAccount $account): SyncRun
    {
        $this->reconcile->reconcile($account->id, $run->id);

        // Claim-time instant: everything that marks "a run started" shares it.
        $now = new CarbonImmutable;

        $claimed = SyncRun::query()
            ->whereKey($run->id)
            ->where('status', SyncStatus::Queued->value)
            ->update(['status' => SyncStatus::Running->value, 'stage' => SyncStage::Credentials->value, 'started_at' => $now]);

        if ($claimed === 0) {
            // Claimed or finished elsewhere; nothing left to do.
            return $run->fresh() ?? $run;
        }

        $account->last_attempt_at = $now;
        $account->save();

        try {
            $adapter = $this->registry->for($account->provider_key);
        } catch (UnsupportedProviderException $exception) {
            return $this->fail($run, $this->providerWarning($exception));
        }

        $credential = $account->credentials()->latest('id')->first();

        if ($credential === null) {
            return $this->fail($run, 'account has no stored credentials');
        }

        $context = new SyncContext($account, $credential->payload, $now);
        $redactor = new Redactor($context->credentials);

        if ($credential->verified_at === null
            || $credential->fingerprint !== $context->credentialFingerprint()
        ) {
            try {
                $check = $this->retry->execute(fn () => $adapter->validateCredentials($context));
            } catch (InvalidCredentialsException $exception) {
                return $this->finishWithRejectedCredentials($run, $credential, $exception, $redactor);
            } catch (ProviderException $exception) {
                return $this->fail($run, $this->providerWarning($exception), redactor: $redactor);
            }

            if (! $check->valid) {
                return $this->fail($run, $check->warning ?? 'credentials are invalid', redactor: $redactor);
            }

            $credential->verified_at = $now;
            $credential->fingerprint = $context->credentialFingerprint();
            $credential->save();
        }

        try {
            $capabilities = $this->retry->execute(fn () => $adapter->capabilities());
        } catch (ProviderException $exception) {
            return $this->fail($run, $this->providerWarning($exception), redactor: $redactor);
        }

        if (! $capabilities->supports(ProviderCapability::Inventory)) {
            return $this->fail($run, 'adapter does not support the inventory capability', redactor: $redactor);
        }

        $warnings = [];
        $counts = [];
        $outcomes = [];

        // --- Inventory phase -------------------------------------------------
        $this->markStage($run, SyncStage::Inventory);

        $inventoryBatch = null;
        $inventoryError = null;

        try {
            $inventoryBatch = $this->retry->execute(fn () => $adapter->fetchInventory($context));
            // Adapter warnings are sanitized by contract; take them before
            // validation so an invalid batch still reports what it saw.
            $warnings = [...$warnings, ...$inventoryBatch->warnings];
            $this->validation->inventory($inventoryBatch);
            $outcomes[ProviderCapability::Inventory->value] = new CapabilityOutcome(
                ProviderCapability::Inventory,
                $inventoryBatch->completeness,
                $inventoryBatch->observedAt,
            );
        } catch (InvalidCredentialsException $exception) {
            $this->recordCapabilities->record($account, $capabilities, [
                ProviderCapability::Inventory->value => new CapabilityOutcome(
                    ProviderCapability::Inventory,
                    BatchCompleteness::Failed,
                ),
            ], $now);

            return $this->finishWithRejectedCredentials($run, $credential, $exception, $redactor);
        } catch (ProviderException $exception) {
            $inventoryError = $this->providerWarning($exception);
            $outcomes[ProviderCapability::Inventory->value] = new CapabilityOutcome(
                ProviderCapability::Inventory,
                BatchCompleteness::Failed,
            );
        }

        if ($outcomes[ProviderCapability::Inventory->value]->completeness === BatchCompleteness::Failed) {
            $this->recordCapabilities->record($account, $capabilities, $outcomes, $now);

            return $this->fail($run, $inventoryError ?? 'inventory fetch failed', $warnings, $redactor);
        }

        // --- Cost facts phase ------------------------------------------------
        $costBatch = null;
        $costFailed = false;
        $costCapabilities = $capabilities->costCapabilities();

        if ($costCapabilities !== []) {
            $this->markStage($run, SyncStage::Costs);

            try {
                $costBatch = $this->retry->execute(
                    fn () => $adapter->fetchCostFacts($context, $inventoryBatch),
                );
                $warnings = [...$warnings, ...$costBatch->warnings];
                $this->validation->costFacts($costBatch, $inventoryBatch);
            } catch (InvalidCredentialsException $exception) {
                return $this->finishWithRejectedCredentials($run, $credential, $exception, $redactor);
            } catch (ProviderException $exception) {
                $costBatch = null;
                $costFailed = true;
                $warnings[] = $this->providerWarning($exception);
            }

            if ($costBatch !== null && $costBatch->completeness->producedUsableData()) {
                foreach ($costCapabilities as $capability) {
                    $outcomes[$capability->value] = new CapabilityOutcome(
                        $capability,
                        $costBatch->completenessFor($capability),
                        $costBatch->observedAt,
                    );
                }
            } else {
                // Attempted but produced nothing usable.
                foreach ($costCapabilities as $capability) {
                    $outcomes[$capability->value] = new CapabilityOutcome(
                        $capability,
                        BatchCompleteness::Failed,
                    );
                }
            }
        }

        // --- Persistence (short transaction, only usable data) ---------------
        $this->markStage($run, SyncStage::Persisting);

        $counts = [];

        [$inventoryCounts, $costCounts] = DB::transaction(function () use ($account, $inventoryBatch, $costBatch, $costCapabilities): array {
            $persisted = $this->persistInventory->persist($account->id, $inventoryBatch);
            $inventoryCounts = ['seen' => count($inventoryBatch->items), 'created' => $persisted->created, 'updated' => $persisted->updated];

            $costCounts = null;

            if ($costBatch !== null && $costBatch->completeness->producedUsableData()) {
                $facts = $this->persistCostFacts->persist($account->provider_key, $account->id, $costBatch, $persisted->services);
                $costCounts = ['seen' => count($costBatch->facts), 'created' => $facts->created, 'superseded' => $facts->superseded, 'updated' => $facts->updated, 'renewals' => $facts->renewals];

                // Ending an unreported charge takes positive evidence that
                // the whole cost phase was complete — not just the overall
                // flag: a per-capability partial must end nothing. The
                // inventory must be complete too: facts are built only for
                // inventoried services, so a partial inventory hides a
                // service whose metadata fetch failed, and ending its
                // charge would treat the absence as cancellation — the
                // thing the lifecycle refuses to do.
                if ($this->mayEndAbsentCharges($inventoryBatch, $costBatch, $costCapabilities)) {
                    $costCounts['ended'] = $this->endAbsentCostFacts->end($account->provider_key, $account->id, $costBatch);
                }
            }

            return [$inventoryCounts, $costCounts];
        });

        $counts['inventory'] = $inventoryCounts;

        if ($costCounts !== null) {
            $counts['cost_facts'] = $costCounts;
        }

        // --- Lifecycle: only a complete inventory may mark missing (docs/architecture.md, sync algorithm) ---
        $this->applyLifecycle->apply($account, $inventoryBatch);

        $this->recordCapabilities->record($account, $capabilities, $outcomes, $now);

        // --- Run status -------------------------------------------------------
        $status = $this->statusFor($outcomes, $costCapabilities, $costFailed, $costBatch);

        if (in_array($status, [SyncStatus::Succeeded, SyncStatus::Partial], true)) {
            $account->last_success_at = new CarbonImmutable;
            $account->save();

            // The sync may have moved material inputs; the snapshot's
            // checksum dedupes, so an unchanged run adds no noise.
            $this->takeSnapshot->capture();
        }

        return $this->finish($run, $status, $counts, $warnings, $redactor);
    }

    /**
     * Absence is cancellation evidence only for capabilities this
     * batch actually represents, observed completely: a complete
     * subscription observation is positive evidence about
     * subscriptions even while the same run's usage capability sits at
     * partial — usage is not what this batch reported, so its
     * partiality cannot undercut the subscriptions' evidence. A batch
     * that names no reported capabilities keeps the conservative
     * legacy rule: every declared cost capability must be complete.
     *
     * @param  list<ProviderCapability>  $costCapabilities
     */
    private function mayEndAbsentCharges(InventoryBatch $inventoryBatch, CostFactBatch $costBatch, array $costCapabilities): bool
    {
        if ($inventoryBatch->completeness !== BatchCompleteness::Complete
            || $costBatch->completeness !== BatchCompleteness::Complete
        ) {
            return false;
        }

        if ($costBatch->reportedCapabilities === []) {
            return collect($costCapabilities)->every(
                fn (ProviderCapability $capability): bool => $costBatch->completenessFor($capability) === BatchCompleteness::Complete,
            );
        }

        return collect($costBatch->reportedCapabilities)->every(
            fn (ProviderCapability $capability): bool => $costBatch->completenessFor($capability) === BatchCompleteness::Complete,
        );
    }

    /**
     * Inventory completeness decides: failed fails the run, partial
     * degrades it; a failed cost phase only degrades the run, because
     * the inventory data is good and must not be thrown away.
     *
     * @param  array<string, CapabilityOutcome>  $outcomes
     * @param  list<ProviderCapability>  $costCapabilities
     */
    private function statusFor(array $outcomes, array $costCapabilities, bool $costFailed, ?CostFactBatch $costBatch): SyncStatus
    {
        $inventory = $outcomes[ProviderCapability::Inventory->value]->completeness;

        if ($inventory === BatchCompleteness::Failed) {
            return SyncStatus::Failed;
        }

        if ($inventory === BatchCompleteness::Partial) {
            return SyncStatus::Partial;
        }

        if ($costCapabilities === []) {
            return SyncStatus::Succeeded;
        }

        if ($costFailed || $costBatch === null) {
            return SyncStatus::Partial;
        }

        $degraded = array_filter(
            $costCapabilities,
            fn (ProviderCapability $capability): bool => ! in_array(
                $costBatch->completenessFor($capability),
                [BatchCompleteness::Complete, BatchCompleteness::Unsupported],
                true,
            ),
        );

        return $degraded === [] ? SyncStatus::Succeeded : SyncStatus::Partial;
    }

    private function finishWithRejectedCredentials(SyncRun $run, ProviderCredential $credential, InvalidCredentialsException $exception, Redactor $redactor): SyncRun
    {
        // The provider just rejected these credentials; the local
        // "verified" mark is a lie until a human fixes them. Clearing it
        // makes the next run fail fast at validation.
        $credential->verified_at = null;
        $credential->save();

        return $this->fail($run, $this->providerWarning($exception), redactor: $redactor);
    }

    /**
     * Finish the run as failed with its primary cause. Every failure
     * path funnels through here, so a failed run always carries an
     * actionable summary['error'].
     *
     * @param  list<string>  $warnings
     */
    private function fail(SyncRun $run, string $error, array $warnings = [], ?Redactor $redactor = null): SyncRun
    {
        return $this->finish($run, SyncStatus::Failed, [], $warnings, $redactor, $error);
    }

    /**
     * Finish the run with counts and a sanitized summary: warnings carry
     * non-fatal notes, and a failed run also gets the primary error —
     * the actionable cause the activity view shows. The update is
     * claim-guarded: only a queued or running run can be finished, so a
     * worker that outlived its lock (or its reconcile) can never
     * resurrect a run someone else already terminated.
     *
     * @param  array<string, mixed>  $counts
     * @param  list<string>  $warnings
     * @param  Redactor|null  $redactor  redacts credential material from everything stored
     * @param  string|null  $error  primary failure cause, stored as summary['error']; failed runs pass it, others never do
     */
    private function finish(SyncRun $run, SyncStatus $status, array $counts, array $warnings, ?Redactor $redactor = null, ?string $error = null): SyncRun
    {
        $redactor ??= new Redactor([]);
        $warnings = array_map(fn (string $warning): string => $redactor->message($warning), $warnings);
        $finishedAt = new CarbonImmutable;

        $summary = [];

        if ($error !== null) {
            $summary['error'] = $redactor->message($error);
        }

        if ($warnings !== []) {
            $summary['warnings'] = $warnings;
        }

        $run->status = $status;
        $run->finished_at = $finishedAt;
        $run->counts = $counts === [] ? null : $counts;
        $run->summary = $summary === [] ? null : $summary;

        $claimed = SyncRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [SyncStatus::Queued->value, SyncStatus::Running->value])
            ->update([
                'status' => $status->value,
                'finished_at' => $finishedAt,
                'counts' => $run->counts,
                'summary' => $run->summary,
            ]);

        if ($claimed === 0) {
            // Someone else already terminated this run; their record wins.
            return $run->fresh() ?? $run;
        }

        $this->logFinished($status, $run, $warnings, $summary['error'] ?? null);

        return $run;
    }

    /**
     * Progress marker for the activity view. Claim-guarded like finish():
     * only a still-running run moves forward, so a worker that outlived
     * its lock can never scribble on a run someone else terminated.
     */
    private function markStage(SyncRun $run, SyncStage $stage): void
    {
        // Keep the in-memory model honest with the row it just wrote.
        $run->stage = $stage;

        SyncRun::query()
            ->whereKey($run->id)
            ->where('status', SyncStatus::Running->value)
            ->update(['stage' => $stage->value]);
    }

    /**
     * @param  list<string>  $warnings
     * @param  string|null  $error  primary failure cause, or null when the run did not fail
     */
    private function logFinished(SyncStatus $status, SyncRun $run, array $warnings, ?string $error = null): void
    {
        $context = [
            'provider_account_id' => $run->provider_account_id,
            'sync_run_id' => $run->id,
            'status' => $status->value,
            'warnings' => $warnings,
            'error' => $error,
        ];

        match ($status) {
            SyncStatus::Failed => Log::error('Sync run failed.', $context),
            SyncStatus::Succeeded => Log::info('Sync run finished.', $context),
            default => Log::warning('Sync run finished with warnings.', $context),
        };
    }

    /**
     * Short exception class plus message: the class name distinguishes a
     * provider outage from an adapter bug, and all these classes are ours.
     */
    private function providerWarning(ProviderException $exception): string
    {
        $shortClass = Str::afterLast($exception::class, '\\');

        return sprintf('%s: %s', $shortClass, $exception->getMessage());
    }
}
