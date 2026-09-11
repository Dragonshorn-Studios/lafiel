<?php

namespace App\Domain\Sync;

use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;

/**
 * Marks abandoned runs failed. A queued or running run older than the
 * lock TTL has lost its worker (crash, deploy); without this, it would
 * block every future sync for the account forever.
 */
final class ReconcileStaleRuns
{
    public function reconcile(int $accountId, ?int $exceptRunId = null): int
    {
        $staleBefore = (new CarbonImmutable)->subSeconds((int) config('sync.lock_ttl_seconds', 600));

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
