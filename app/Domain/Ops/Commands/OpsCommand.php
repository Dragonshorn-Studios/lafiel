<?php

namespace App\Domain\Ops\Commands;

use App\Domain\Ops\OpsHealth;
use Illuminate\Console\Command;

final class OpsCommand extends Command
{
    protected $signature = 'lafiel:ops';

    protected $description = 'Run the pipeline health checks (scheduler, queue, credentials, snapshots)';

    public function handle(OpsHealth $health): int
    {
        $failed = false;

        $rows = array_map(function ($check) use (&$failed): array {
            if ($check->critical && ! $check->ok) {
                $failed = true;
            }

            return [
                $check->check,
                $check->ok ? 'ok' : 'FAIL',
                $check->critical ? 'critical' : 'warning',
                $check->detail,
            ];
        }, $health->checks());

        $this->table(['Check', 'Status', 'Severity', 'Detail'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
