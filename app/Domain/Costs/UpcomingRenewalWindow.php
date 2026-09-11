<?php

namespace App\Domain\Costs;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;

/**
 * One renewal window snapshot: the rows the Overview renders and the
 * per-currency sums behind the "upcoming" metric. Charges with an
 * unknown amount appear as rows but contribute to no total — visible
 * incompleteness, never a hidden zero.
 */
final readonly class UpcomingRenewalWindow
{
    /**
     * @param  list<array{name: string, provider: string, amount: ?Money, renews_at: CarbonImmutable, auto_renew: bool, cost_item: CostItem}>  $rows
     * @param  array<string, int>  $totalsMinor  currency => minor sum
     */
    public function __construct(
        public array $rows,
        public array $totalsMinor,
        public int $unknownCount,
    ) {}

    /**
     * The total in the display currency, or null when nothing is priced
     * in it within the window.
     */
    public function totalFor(string $currency): ?int
    {
        return $this->totalsMinor[$currency] ?? null;
    }
}
