<?php

namespace App\Domain\Costs\Projection;

use Carbon\CarbonImmutable;

/**
 * The monthly burn series behind the Overview chart. Every point is a
 * full projection over the stored cost items as of that month's end —
 * past months are recomputed, not snapshotted, so backdated or ended
 * charges rewrite them — and the chart never sums money by itself.
 * The current, unfinished month is projected as of now and flagged
 * estimated so the UI can draw it dashed. Totals are per the display
 * currency; a month with no charges in it contributes zero.
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

        // Anchor from the start of the current month: subtracting N
        // months from e.g. March 31st overflows in Carbon and would
        // corrupt the labels (two "May", no "April").
        $currentMonth = $now->startOfMonth();

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = $currentMonth->subMonths($offset);
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
