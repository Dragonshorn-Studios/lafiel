<?php

namespace App\Domain\Sync;

use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;

/**
 * Marks abandoned runs failed. A queued or running run older than the
 * stale-run threshold has lost its worker (crash, deploy); without
 * this, it would block every future sync for the account forever.
 * The threshold is deliberately longer than the lock TTL: a live run
 * whose lock lapsed cannot corrupt anything (the claim-guarded finish
 * refuses to overwrite terminal states), it just slows recovery.
 */
final class ReconcileStaleRuns
{
    /**
     * @param  int|null  $exceptRunId  run to exempt, typically the caller's own in-flight run
     */
    public function reconcile(int $accountId, ?int $exceptRunId = null): int
    {
        $staleBefore = (new CarbonImmutable)
            ->subSeconds((int) config('sync.stale_run_after_seconds', 1800));

        return SyncRun::query()
            ->where('provider_account_id', $accountId)
            ->whereIn('status', [SyncStatus::Queued->value, SyncStatus::Running->value])
            ->where('created_at', '<', $staleBefore)
            ->when($exceptRunId !== null, fn ($query) => $query->whereKeyNot($exceptRunId))
            ->update([
                'status' => SyncStatus::Failed->value,
                'finished_at' => (new CarbonImmutable),
                'summary' => ['warnings' => ['abandoned before completion']],
            ]);
    }
}
