<?php

namespace App\Domain\Sync\Jobs;

use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One queued sync run. Expected provider failures are recorded on the
 * run by the orchestrator and never rethrown; this job only fails on
 * unexpected errors, and its tries=1 keeps queue-level redelivery from
 * creating parallel work — the per-account lock and the active-run
 * index own that invariant.
 */
final class SyncProviderAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncRunId,
    ) {}

    public function handle(SyncOrchestrator $orchestrator): void
    {
        $run = SyncRun::query()->find($this->syncRunId);

        if ($run === null) {
            return;
        }

        $orchestrator->run($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = SyncRun::query()->find($this->syncRunId);

        if ($run === null || ! in_array($run->status->value, ['queued', 'running'], true)) {
            return;
        }

        $run->status = SyncStatus::Failed;
        $run->finished_at = now();
        // The class name only, deliberately: an unexpected error's
        // message is unredacted by construction, and the run id links
        // to the full exception in the application log.
        $run->summary = ['error' => sprintf('sync job failed unexpectedly: %s', $exception !== null ? $exception::class : 'unknown error')];
        $run->save();

        Log::error('Sync job failed unexpectedly.', [
            'sync_run_id' => $run->id,
            'provider_account_id' => $run->provider_account_id,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
