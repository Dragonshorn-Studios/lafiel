<?php

namespace App\Domain\Sync;

use App\Domain\Inventory\Lifecycle\ApplyInventoryLifecycle;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\CapabilityOutcome;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\UnsupportedProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\RecordCapabilityStates;
use App\Domain\Support\Redaction\Redactor;
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

/**
 * The sync algorithm for one provider account, per docs/architecture.md:
 * lock, claim the run, validate credentials only when needed, fetch
 * outside a transaction, validate batch invariants, persist
 * idempotently in a short transaction, apply lifecycle transitions only
 * after a complete inventory, and finish with counts and sanitized
 * warnings.
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
        private readonly ApplyInventoryLifecycle $applyLifecycle,
        private readonly RecordCapabilityStates $recordCapabilities,
        private readonly ReconcileStaleRuns $reconcile,
    ) {}

    public function run(SyncRun $run): SyncRun
    {
        $run->load('providerAccount');
        $account = $run->providerAccount;
        $now = new CarbonImmutable;

        if (! $account->enabled) {
            return $this->finish($run, SyncStatus::Cancelled, [], ['account is disabled'], $now);
        }

        $lock = Cache::lock(
            sprintf('sync:account:%d:full', $account->id),
            (int) config('sync.lock_ttl_seconds', 600),
        );

        // One sync per account. The active-run index guarantees this run
        // is the account's only live run, so a held lock always belongs
        // to a stale worker — fail fast and let the TTL recover it.
        if (! $lock->get()) {
            return $this->finish($run, SyncStatus::Failed, [], ['account lock is held by another sync'], $now);
        }

        try {
            return $this->execute($run, $account, $now);
        } finally {
            $lock->release();
        }
    }

    private function execute(SyncRun $run, ProviderAccount $account, CarbonImmutable $now): SyncRun
    {
        $this->reconcile->reconcile($account->id, $run->id);

        $claimed = SyncRun::query()
            ->whereKey($run->id)
            ->where('status', SyncStatus::Queued->value)
            ->update(['status' => SyncStatus::Running->value, 'started_at' => $now]);

        if ($claimed === 0) {
            // Claimed or finished elsewhere; nothing left to do.
            return $run->fresh() ?? $run;
        }

        $account->last_attempt_at = $now;
        $account->save();

        try {
            $adapter = $this->registry->for($account->provider_key);
        } catch (UnsupportedProviderException $exception) {
            return $this->finish($run, SyncStatus::Failed, [], [$exception->getMessage()], $now);
        }

        $credential = $account->credentials()->latest('id')->first();

        if ($credential === null) {
            return $this->finish($run, SyncStatus::Failed, [], ['account has no stored credentials'], $now);
        }

        $context = new SyncContext($account, $credential->payload, $now);
        $redactor = new Redactor($context->credentials);

        if ($credential->verified_at === null
            || $credential->fingerprint !== $context->credentialFingerprint()
        ) {
            try {
                $check = $this->retry->execute(fn () => $adapter->validateCredentials($context));
            } catch (ProviderException $exception) {
                return $this->finish($run, SyncStatus::Failed, [], [$exception->getMessage()], $now, $redactor);
            }

            if (! $check->valid) {
                return $this->finish($run, SyncStatus::Failed, [], [$check->warning ?? 'credentials are invalid'], $now, $redactor);
            }

            $credential->verified_at = $now;
            $credential->fingerprint = $context->credentialFingerprint();
            $credential->save();
        }

        try {
            $capabilities = $this->retry->execute(fn () => $adapter->capabilities());
        } catch (ProviderException $exception) {
            return $this->finish($run, SyncStatus::Failed, [], [$exception->getMessage()], $now, $redactor);
        }

        if (! $capabilities->supports(ProviderCapability::Inventory)) {
            return $this->finish($run, SyncStatus::Failed, [], ['adapter does not support the inventory capability'], $now, $redactor);
        }

        $warnings = [];
        $counts = [];
        $outcomes = [];

        // --- Inventory phase -------------------------------------------------
        $inventoryBatch = null;

        try {
            $inventoryBatch = $this->retry->execute(fn () => $adapter->fetchInventory($context));
            $this->validation->inventory($inventoryBatch);
            $warnings = [...$warnings, ...$inventoryBatch->warnings];
            $outcomes[ProviderCapability::Inventory->value] = new CapabilityOutcome(
                ProviderCapability::Inventory,
                attempted: true,
                completeness: $inventoryBatch->completeness,
                observedAt: $inventoryBatch->observedAt,
            );
        } catch (ProviderException $exception) {
            $warnings[] = $exception->getMessage();
            $outcomes[ProviderCapability::Inventory->value] = new CapabilityOutcome(
                ProviderCapability::Inventory,
                attempted: true,
                completeness: BatchCompleteness::Failed,
            );
        }

        if ($outcomes[ProviderCapability::Inventory->value]->completeness === BatchCompleteness::Failed) {
            $this->recordCapabilities->record($account, $capabilities, $outcomes, $now);

            return $this->finish($run, SyncStatus::Failed, [], $warnings, $now, $redactor);
        }

        // --- Cost facts phase ------------------------------------------------
        $costBatch = null;
        $costFailed = false;
        $costCapabilities = $capabilities->costCapabilities();

        if ($costCapabilities !== []) {
            try {
                $costBatch = $this->retry->execute(
                    fn () => $adapter->fetchCostFacts($context, $inventoryBatch),
                );
                $this->validation->costFacts($costBatch, $inventoryBatch);
                $warnings = [...$warnings, ...$costBatch->warnings];
            } catch (ProviderException $exception) {
                $costBatch = null;
                $costFailed = true;
                $warnings[] = $exception->getMessage();
            }

            if ($costBatch !== null && $costBatch->completeness->producedUsableData()) {
                foreach ($costCapabilities as $capability) {
                    $outcomes[$capability->value] = new CapabilityOutcome(
                        $capability,
                        attempted: true,
                        completeness: $costBatch->completenessFor($capability),
                        observedAt: $costBatch->observedAt,
                    );
                }
            } else {
                // Attempted but produced nothing usable.
                foreach ($costCapabilities as $capability) {
                    $outcomes[$capability->value] = new CapabilityOutcome(
                        $capability,
                        attempted: true,
                        completeness: BatchCompleteness::Failed,
                    );
                }
            }
        }

        // --- Persistence (short transaction, only usable data) ---------------
        $counts = [];

        [$inventoryCounts, $costCounts] = DB::transaction(function () use ($account, $inventoryBatch, $costBatch): array {
            $persisted = $this->persistInventory->persist($account->id, $inventoryBatch);
            $inventoryCounts = ['seen' => count($inventoryBatch->items), 'created' => $persisted->created, 'updated' => $persisted->updated];

            $costCounts = null;

            if ($costBatch !== null && $costBatch->completeness->producedUsableData()) {
                $facts = $this->persistCostFacts->persist($account->provider_key, $account->id, $costBatch, $persisted->services);
                $costCounts = ['seen' => count($costBatch->facts), 'created' => $facts->created, 'superseded' => $facts->superseded, 'updated' => $facts->updated, 'renewals' => $facts->renewals];

                // A complete batch that no longer reports a charge is
                // evidence the charge ended; partial batches end nothing.
                if ($costBatch->completeness === BatchCompleteness::Complete) {
                    $costCounts['ended'] = $this->endAbsentCostFacts->end($account->provider_key, $account->id, $costBatch);
                }
            }

            return [$inventoryCounts, $costCounts];
        });

        $counts['inventory'] = $inventoryCounts;

        if ($costCounts !== null) {
            $counts['cost_facts'] = $costCounts;
        }

        // --- Lifecycle (step 7): only a complete inventory may mark missing --
        $this->applyLifecycle->apply($account, $inventoryBatch);

        $this->recordCapabilities->record($account, $capabilities, $outcomes, $now);

        // --- Run status -------------------------------------------------------
        $status = $this->statusFor($outcomes, $costCapabilities, $costFailed, $costBatch);

        if (in_array($status, [SyncStatus::Succeeded, SyncStatus::Partial], true)) {
            $account->last_success_at = $now;
            $account->save();
        }

        return $this->finish($run, $status, $counts, $warnings, $now, $redactor);
    }

    /**
     * Inventory decides between succeeded and partial; a failed cost
     * phase only degrades the run, because the inventory data is good
     * and must not be thrown away.
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

    /**
     * @param  array<string, mixed>  $counts
     * @param  list<string>  $warnings
     */
    private function finish(SyncRun $run, SyncStatus $status, array $counts, array $warnings, CarbonImmutable $now, ?Redactor $redactor = null): SyncRun
    {
        $redactor ??= new Redactor([]);
        $warnings = array_map(fn (string $warning): string => $redactor->message($warning), $warnings);

        $run->status = $status;
        $run->finished_at = $now;
        $run->counts = $counts === [] ? null : $counts;
        $run->summary = $warnings === [] ? null : ['warnings' => $warnings];
        $run->save();

        Log::info('Sync run finished.', [
            'provider_account_id' => $run->provider_account_id,
            'sync_run_id' => $run->id,
            'status' => $status->value,
            'warnings' => $warnings,
        ]);

        return $run;
    }
}
