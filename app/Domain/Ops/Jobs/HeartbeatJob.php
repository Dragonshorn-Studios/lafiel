<?php

namespace App\Domain\Ops\Jobs;

use App\Domain\Ops\Ops;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs on the queue once a minute: if the queue worker is dead, this
 * job stops executing and the queue heartbeat goes stale.
 */
final class HeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        cache()->put(Ops::QUEUE_HEARTBEAT, now()->toIso8601String());
    }
}
