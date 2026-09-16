<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Jobs\SyncProviderAccount;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\ReconcileStaleRuns;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single entry point for starting a sync — "sync now", the console
 * command, and future schedules all go through here, so no path can
 * start a parallel run. Enforcement is the partial unique index on
 * sync_runs: a duplicate insert throws and returns null, and reconcile
 * first clears stale runs so they cannot block the insert.
 */
final class RequestSync
{
    public const TRIGGERS = ['manual', 'schedule', 'setup'];

    public function __construct(
        private readonly ReconcileStaleRuns $reconcile,
    ) {}

    /**
     * Queues a sync run and dispatches the job. Returns null when the
     * account already has an active run.
     */
    public function request(ProviderAccount $account, string $trigger = 'manual'): ?SyncRun
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException("Unknown sync trigger [{$trigger}].");
        }

        $this->reconcile->reconcile($account->id);

        try {
            // The partial unique index refuses a second active run. On
            // Postgres that violation aborts any surrounding
            // transaction, so the insert runs in its own savepoint and
            // the catch leaves the caller healthy.
            $run = DB::transaction(fn (): SyncRun => SyncRun::query()->create([
                'provider_account_id' => $account->id,
                'trigger' => $trigger,
                'status' => 'queued',
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        try {
            SyncProviderAccount::dispatch($run->id);
        } catch (Throwable $exception) {
            // A dispatch failure would otherwise leave the run queued
            // with no job behind it, blocking further syncs until the
            // stale-run reconciler aged it out. The exception class
            // only: a queue-layer message is unredacted by construction
            // (host, port, credentials of the broker), and the full
            // message is logged below.
            $run->status = SyncStatus::Failed;
            $run->summary = ['error' => 'could not queue the sync job: '.$exception::class];
            $run->finished_at = now();
            $run->save();

            Log::error('Could not queue sync job.', [
                'sync_run_id' => $run->id,
                'error' => $exception->getMessage(),
            ]);

            return $run;
        }

        return $run;
    }
}
