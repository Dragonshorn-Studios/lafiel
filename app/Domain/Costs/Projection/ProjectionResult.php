<?php

namespace App\Domain\Costs\Projection;

/**
 * The complete projection result for one date: per-currency totals plus
 * the completeness counters. Unknown amounts stay outside the known
 * totals and are counted; the same goes for estimates, stale evidence,
 * and unresolved shared charges.
 */
final readonly class ProjectionResult
{
    /**
     * @param  array<string, CurrencyTotal>  $byCurrency
     */
    public function __construct(
        public string $calculationVersion,
        public array $byCurrency,
        public int $unknownCount = 0,
        public int $estimateCount = 0,
        public int $staleCount = 0,
        public int $sharedUnallocatedCount = 0,
        public int $oneTimeCount = 0,
    ) {}

    public function forCurrency(string $currency): ?CurrencyTotal
    {
        return $this->byCurrency[$currency] ?? null;
    }

    /**
     * The snapshot-ready breakdown: every winning charge line, grouped
     * by currency, plus the completeness counters.
     *
     * @return array<string, mixed>
     */
    public function breakdown(): array
    {
        $lines = [];

        foreach ($this->byCurrency as $total) {
            foreach ($total->lines as $line) {
                $lines[] = [
                    'cost_item_id' => $line->costItemId,
                    'logical_charge_key' => $line->logicalChargeKey,
                    'source_kind' => $line->sourceKind->value,
                    'charge_kind' => $line->chargeKind->value,
                    'evidence_state' => $line->evidenceState->value,
                    'allocation_state' => $line->allocationState->value,
                    'amount_minor' => $line->amount->amountMinor,
                    'currency' => $line->amount->currency,
                    'monthly_minor_rounded' => $line->monthlyEquivalent->roundHalfEven(),
                    'annual_minor_rounded' => $line->annualEquivalent->roundHalfEven(),
                    'is_stale' => $line->isStale,
                ];
            }
        }

        return [
            'calculation_version' => $this->calculationVersion,
            'lines' => $lines,
            'unknown_count' => $this->unknownCount,
            'estimate_count' => $this->estimateCount,
            'stale_count' => $this->staleCount,
            'shared_unallocated_count' => $this->sharedUnallocatedCount,
            'one_time_count' => $this->oneTimeCount,
        ];
    }
}
