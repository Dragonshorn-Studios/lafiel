<?php

namespace App\Domain\Ops\Commands;

use App\Domain\Ops\OpsHealth;
use Illuminate\Console\Command;

final class OpsCommand extends Command
{
    protected $signature = 'lafiel:ops';

    protected $description = 'Run the pipeline health checks (database, scheduler, queue, credentials, snapshots)';

    public function handle(OpsHealth $health): int
    {
        $failed = false;

        $rows = [];

        foreach ($health->checks() as $check) {
            if ($check->isFailure()) {
                $failed = true;
            }

            $rows[] = [
                $check->name,
                $check->ok ? 'ok' : 'FAIL',
                $check->critical ? 'critical' : 'warning',
                $check->detail,
            ];
        }

        $this->table(['Check', 'Status', 'Severity', 'Detail'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
