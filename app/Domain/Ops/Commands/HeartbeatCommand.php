<?php

namespace App\Domain\Ops\Commands;

use App\Domain\Ops\Ops;
use Illuminate\Console\Command;

final class HeartbeatCommand extends Command
{
    protected $signature = 'lafiel:heartbeat';

    protected $description = 'Record the scheduler heartbeat (runs every minute via the schedule)';

    public function handle(): int
    {
        // No TTL: liveness is judged by the timestamp's age, so a dead
        // scheduler leaves a readable, stale beat behind.
        cache()->put(Ops::SCHEDULER_HEARTBEAT, now()->toIso8601String());

        return self::SUCCESS;
    }
}
