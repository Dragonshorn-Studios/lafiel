<?php

namespace App\Domain\History\Commands;

use App\Domain\History\TakeSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class TakeSnapshotCommand extends Command
{
    protected $signature = 'lafiel:snapshot
        {--date= : Capture a specific date (Y-m-d, not in the future) instead of today}';

    protected $description = 'Capture the daily cost snapshot (replays a specific date with --date)';

    public function handle(TakeSnapshot $takeSnapshot): int
    {
        $onDate = CarbonImmutable::now();

        if (($date = $this->option('date')) !== null) {
            if (! is_string($date) || ! CarbonImmutable::hasFormat($date, 'Y-m-d')) {
                $this->error('The date must be a Y-m-d value.');

                return self::FAILURE;
            }

            $onDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);

            if ($onDate->isAfter(CarbonImmutable::today())) {
                $this->error('The date must not be in the future.');

                return self::FAILURE;
            }
        }

        $capture = $takeSnapshot->capture($onDate);

        if ($capture === null) {
            $this->error('Snapshot capture failed. Check the logs.');

            return self::FAILURE;
        }

        $this->info(
            $capture->written
                ? "Snapshot for {$capture->snapshot->snapshot_date->format('Y-m-d')} written."
                : "Snapshot for {$capture->snapshot->snapshot_date->format('Y-m-d')} unchanged.",
        );

        return self::SUCCESS;
    }
}
