<?php

namespace App\Domain\Sync\Commands;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Actions\RequestSync;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Queues a provider sync for one or all enabled accounts. This is the
 * same path a UI "sync now" action uses: one entry point, one job,
 * never a parallel run.
 */
final class SyncNowCommand extends Command
{
    protected $signature = 'lafiel:sync
        {--account=* : Provider account id (repeatable; defaults to every enabled account)}
        {--trigger=manual : Sync trigger recorded on the run (manual, schedule, setup)}';

    protected $description = 'Queue a provider account sync';

    public function handle(RequestSync $requestSync): int
    {
        $triggerOption = $this->option('trigger');
        $trigger = is_array($triggerOption) ? implode(',', $triggerOption) : (string) $triggerOption;

        if (! in_array($trigger, RequestSync::TRIGGERS, true)) {
            $this->error("Unknown trigger [{$trigger}]. Use one of: ".implode(', ', RequestSync::TRIGGERS).'.');

            return self::FAILURE;
        }

        /** @var Collection<int, ProviderAccount> $accounts */
        $accounts = ProviderAccount::query()
            ->where('enabled', true)
            ->when($this->option('account') !== [], fn ($query) => $query->whereKey((array) $this->option('account')))
            ->orderBy('id')
            ->get();

        $requested = (array) $this->option('account');

        if ($requested !== [] && $accounts->count() !== count(array_unique($requested))) {
            $this->error('One or more accounts were not found or are disabled.');

            return self::FAILURE;
        }

        if ($accounts->isEmpty()) {
            $this->info('No enabled provider accounts to sync.');

            return self::SUCCESS;
        }

        $couldNotQueue = 0;

        foreach ($accounts as $account) {
            $run = $requestSync->request($account, $trigger);

            if ($run === null) {
                $this->line(" [{$account->id}] {$account->display_name}: already syncing, skipped.");

                continue;
            }

            // A dispatch failure is not "skipped": say so and fail the
            // command so exit-code-based monitoring sees it.
            if ($run->status->value === 'failed') {
                $couldNotQueue++;
                $warning = $run->summary['warnings'][0] ?? 'could not queue the sync job';
                $this->error(" [{$account->id}] {$account->display_name}: {$warning}");

                continue;
            }

            $this->info(" [{$account->id}] {$account->display_name}: sync queued (run #{$run->id}).");
        }

        return $couldNotQueue > 0 ? self::FAILURE : self::SUCCESS;
    }
}
