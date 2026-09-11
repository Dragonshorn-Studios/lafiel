<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Jobs\SyncProviderAccount;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\ReconcileStaleRuns;
use Illuminate\Database\UniqueConstraintViolationException;

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
            $run = SyncRun::query()->create([
                'provider_account_id' => $account->id,
                'trigger' => $trigger,
                'status' => 'queued',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        SyncProviderAccount::dispatch($run->id);

        return $run;
    }
}
