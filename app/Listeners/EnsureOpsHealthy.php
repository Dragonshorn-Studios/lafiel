<?php

namespace App\Listeners;

use App\Domain\Ops\OpsHealth;
use Illuminate\Foundation\Events\DiagnosingHealth;

/**
 * Fails the health endpoint when a critical pipeline check fails: a
 * stale scheduler or queue heartbeat, or unreadable credentials. The
 * database check runs first; this keeps the container health state —
 * and therefore Coolify/compose — visibly red while the pipeline is
 * broken. Fresh installs are graced by OpsHealth.
 */
class EnsureOpsHealthy
{
    public function handle(DiagnosingHealth $event): void
    {
        $failures = app(OpsHealth::class)->criticalFailures();

        if ($failures !== []) {
            $names = implode(', ', array_map(fn ($failure) => $failure->name.' ('.$failure->detail.')', $failures));

            throw new \RuntimeException("Pipeline check failed: {$names}.");
        }
    }
}
