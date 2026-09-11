<?php

namespace App\Domain\History\Commands;

use App\Domain\History\TakeSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class TakeSnapshotCommand extends Command
{
    protected $signature = 'lafiel:snapshot
        {--date= : Capture an explicit date (Y-m-d) instead of today}';

    protected $description = 'Capture the daily cost snapshot (replay a past date with --date)';

    public function handle(TakeSnapshot $takeSnapshot): int
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '') {
            try {
                $onDate = new CarbonImmutable($date);
            } catch (\InvalidArgumentException) {
                $this->error('The date must be a valid Y-m-d value.');

                return self::FAILURE;
            }
        } else {
            $onDate = CarbonImmutable::now();
        }

        $snapshot = $takeSnapshot->capture($onDate);

        $this->info("Snapshot for {$snapshot->snapshot_date->format('Y-m-d')} (v{$snapshot->calculation_version}).");

        return self::SUCCESS;
    }
}
