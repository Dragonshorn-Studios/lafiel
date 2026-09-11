<?php

namespace App\Domain\Inventory\Lifecycle;

use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Models\ProviderAccount;
use Illuminate\Support\Collection;

/**
 * Applies seen/missing/inactive transitions after one inventory batch.
 *
 * A service the batch observed is present: its last-seen refreshes and
 * it returns to active, whatever the batch completeness — presence in a
 * partial batch is still positive evidence. A service omitted from a
 * complete batch accumulates a missing run; after the configured number
 * of consecutive complete omissions it turns inactive. Omission from a
 * partial batch, or from a failed run (which has no batch at all),
 * never advances the counter. Absence is never cancellation, and rows
 * are never deleted.
 */
final class ApplyInventoryLifecycle
{
    public function apply(ProviderAccount $account, InventoryBatch $batch): void
    {
        $complete = $batch->completeness === BatchCompleteness::Complete;
        $presentIds = array_map(
            fn (object $item): string => $item->externalId,
            $batch->items,
        );

        /** @var Collection<string, Service> $services */
        $services = Service::query()
            ->where('provider_account_id', $account->id)
            ->whereNotNull('external_id')
            ->get()
            ->keyBy('external_id');

        foreach ($services as $externalId => $service) {
            if (in_array($externalId, $presentIds, true)) {
                $service->last_seen_at = $batch->observedAt;
                $service->lifecycle_state = ServiceLifecycle::Active;
                $service->missing_complete_runs = 0;
                $service->save();

                continue;
            }

            if (! $complete) {
                continue;
            }

            $service->missing_complete_runs++;
            $service->lifecycle_state = $service->missing_complete_runs >= $this->threshold()
                ? ServiceLifecycle::Inactive
                : ServiceLifecycle::Missing;
            $service->save();
        }
    }

    private function threshold(): int
    {
        return max(1, (int) config('sync.inactive_after_complete_runs', 3));
    }
}
