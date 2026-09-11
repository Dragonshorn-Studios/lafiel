<?php

namespace App\Domain\Costs\Projection;

use Carbon\CarbonImmutable;

/**
 * The monthly burn series behind the Overview chart. Every point is a
 * full projection at that month's end — the chart never sums money by
 * itself. Completed months are ledger facts; the current, unfinished
 * month is the projection as of now and is flagged estimated so the UI
 * can draw it dashed. Totals are per the display currency; a month
 * with no charges contributes zero.
 */
class SpendHistory
{
    public function __construct(private readonly CostProjector $projector) {}

    /**
     * @param  int  $months  number of points, including the current month
     * @return list<array{label: string, month: CarbonImmutable, minor: int, currency: string, estimated: bool}>
     */
    public function monthly(CarbonImmutable $now, int $months = 6): array
    {
        $currency = (string) config('costs.display_currency', 'PLN');

        $series = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = $now->subMonths($offset);
            $observedAt = $offset === 0 ? $now : $month->endOfMonth();

            $result = $this->projector->project($observedAt);
            $total = $result->forCurrency($currency);

            $series[] = [
                'label' => $month->format('M Y'),
                'month' => $month,
                'minor' => $total === null ? 0 : $total->monthlyMinor,
                'currency' => $currency,
                'estimated' => $offset === 0,
            ];
        }

        return $series;
    }
}
