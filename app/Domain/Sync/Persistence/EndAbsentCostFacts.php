<?php

namespace App\Domain\Sync\Persistence;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Providers\Dtos\CostFact;
use App\Domain\Providers\Dtos\CostFactBatch;

/**
 * Ends the charges a successful COMPLETE cost-facts batch no longer
 * reports: a complete observation is positive evidence that the charge
 * ended, so its last charged day is the day before the observation.
 * Partial or failed batches end nothing — their absence is not
 * cancellation, and the last good data stays untouched. Renewals on
 * ended facts remain as historical evidence.
 */
final class EndAbsentCostFacts
{
    public function end(string $providerKey, int $accountId, CostFactBatch $batch): int
    {
        $prefix = sprintf('%s:account:%d:charge:', $providerKey, $accountId);

        $reported = array_map(
            fn (CostFact $fact): string => $prefix.$fact->sourceRef,
            $batch->facts,
        );

        // An empty complete batch ends every open charge of the account:
        // whereNotIn with no reported keys matches all of them.
        return CostItem::query()
            ->whereNull('valid_to')
            ->where('logical_charge_key', 'like', $prefix.'%')
            ->whereNotIn('logical_charge_key', $reported)
            ->update([
                'valid_to' => $batch->observedAt->subDay()->startOfDay(),
            ]);
    }
}
