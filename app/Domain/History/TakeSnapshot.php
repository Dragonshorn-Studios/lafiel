<?php

namespace App\Domain\History;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\History\Models\CostSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Captures one daily cost snapshot: the projection for the date, its
 * completeness counters, and a checksum over the material inputs, so
 * identical runs add no noise. One row per date — a later capture on
 * the same date updates the row only when the inputs or the
 * calculation actually changed. `fx_used` stays null in v1 (there is
 * no FX source) and is never written after capture: today's rate must
 * not rewrite history. Snapshots are historical output, never a
 * second source of truth.
 */
class TakeSnapshot
{
    public function __construct(private readonly CostProjector $projector) {}

    /**
     * @return CostSnapshot the written snapshot, or the existing
     *                      unchanged one when inputs produced no noise
     */
    public function capture(?CarbonImmutable $onDate = null): CostSnapshot
    {
        $onDate ??= CarbonImmutable::now();

        $result = $this->projector->project($onDate);
        $checksum = $this->inputChecksum();

        $existing = CostSnapshot::query()
            ->whereDate('snapshot_date', $onDate->toDateString())
            ->first();

        if ($existing !== null
            && $existing->input_checksum === $checksum
            && $existing->calculation_version === $result->calculationVersion) {
            return $existing;
        }

        $totals = [];

        foreach ($result->currencies() as $total) {
            $totals[$total->currency] = [
                'monthly_minor' => $total->monthlyMinor,
                'annual_minor' => $total->annualMinor,
                'one_time_minor' => $total->oneTimeMinor,
            ];
        }

        $completeness = [
            'priced' => $result->pricedCount(),
            'unknown' => $result->unknownCount,
            'estimate' => $result->estimateCount,
            'stale' => $result->staleCount,
            'shared_unallocated' => $result->sharedUnallocatedCount,
            'one_time' => $result->oneTimeCount,
        ];

        $payload = [
            'totals' => $totals,
            'completeness' => $completeness,
            'calculation_version' => $result->calculationVersion,
            'breakdown' => $result->breakdown(),
            'input_checksum' => $checksum,
        ];

        if ($existing !== null) {
            // fx_used is intentionally absent: a captured rate is never
            // rewritten by a later capture.
            $existing->fill($payload)->save();

            return $existing;
        }

        try {
            return CostSnapshot::query()->create([...$payload, 'snapshot_date' => $onDate]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent capture for the same date won the unique
            // index; its row stands.
            return CostSnapshot::query()->whereDate('snapshot_date', $onDate->toDateString())->firstOrFail();
        }
    }

    /**
     * Checksum over the material inputs of the projection: every cost
     * item's price and validity dimensions, the coverage pairs, and
     * the renewals. Evidence observation times and timestamps are
     * excluded — a re-sync that changed nothing must not read as a
     * material change.
     */
    private function inputChecksum(): string
    {
        $items = CostItem::query()
            ->orderBy('id')
            ->get([
                'id', 'identity_key', 'logical_charge_key', 'source_kind',
                'charge_kind', 'period', 'amount_minor', 'currency',
                'amount_state', 'evidence_state', 'tax_basis',
                'allocation_state', 'is_manual_override', 'valid_from',
                'valid_to',
            ]);

        $coverage = DB::table('cost_item_services')
            ->orderBy('cost_item_id')
            ->orderBy('service_id')
            ->get(['cost_item_id', 'service_id']);

        $renewals = DB::table('renewals')
            ->orderBy('cost_item_id')
            ->get(['cost_item_id', 'renews_at', 'auto_renew']);

        return hash('sha256', (string) json_encode([$items, $coverage, $renewals], JSON_THROW_ON_ERROR));
    }
}
