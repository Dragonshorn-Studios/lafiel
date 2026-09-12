<?php

namespace App\Domain\Costs\Projection;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Support\ValueObjects\Rational;

/**
 * The complete projection result for one date: per-currency totals plus
 * the completeness counters. Unknown amounts stay outside the known
 * totals and are counted; the same goes for estimates, stale evidence,
 * and unresolved shared charges. Totals are exposed through accessors
 * so consumers cannot inject lines that bypassed the rounded-once sum.
 */
final readonly class ProjectionResult
{
    /**
     * @param  array<string, CurrencyTotal>  $byCurrency
     */
    public function __construct(
        public string $calculationVersion,
        private array $byCurrency,
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
     * How many winning charges carry a known amount with a known,
     * recurring cadence — the "N priced" half of the coverage display.
     * The other half is counted in $unknownCount, which also includes
     * known amounts whose cadence is unknown or one-time.
     */
    public function pricedCount(): int
    {
        $count = 0;

        foreach ($this->byCurrency as $total) {
            $count += count($total->lines());
        }

        return $count;
    }

    /**
     * Recurring monthly spend grouped by provider label for one
     * currency, in minor units. One-time charges stay out — they are
     * not monthly spend, and folding them in would misattribute their
     * full amount to a provider's recurring share. Each provider's
     * share is rounded from its exact rational sum; the largest share
     * absorbs the rounding residual so the split always adds up to the
     * rounded-once recurring total the metrics show. Presentation of
     * lines only — the totals stay untouched.
     *
     * @return array<string, int> provider label => monthly minor
     */
    public function providerSplit(string $currency): array
    {
        $total = $this->forCurrency($currency);

        if ($total === null) {
            return [];
        }

        $exact = [];

        foreach ($total->lines() as $line) {
            if ($line->chargeKind === ChargeKind::OneTime) {
                continue;
            }

            $exact[$line->provider] = ($exact[$line->provider] ?? new Rational(0))
                ->add($line->monthlyEquivalent);
        }

        uasort($exact, fn (Rational $a, Rational $b): int => $b->roundHalfEven() <=> $a->roundHalfEven());

        $split = [];
        $roundedSum = 0;

        foreach ($exact as $provider => $rational) {
            $split[$provider] = $rational->roundHalfEven();
            $roundedSum += $split[$provider];
        }

        $largest = array_key_first($split);

        if ($largest !== null) {
            $split[$largest] += $total->monthlyMinor - $roundedSum;
        }

        return $split;
    }

    /**
     * @return list<CurrencyTotal>
     */
    public function currencies(): array
    {
        return array_values($this->byCurrency);
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
            foreach ($total->lines() as $line) {
                $lines[] = [
                    'cost_item_id' => $line->costItemId,
                    'logical_charge_key' => $line->logicalChargeKey,
                    'provider' => $line->provider,
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
