<?php

namespace App\Domain\Costs;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;

/**
 * Read model for the renewal window: every renewal attached to a still
 * open charge, renews within the given number of days. The Overview
 * renders what this returns — service, provider, amount, date — and
 * the per-currency totals for the "upcoming" metric; it never sums
 * money on its own. Amounts are the charge's source amount, not
 * normalized: an annual renewal shows its annual price.
 */
class UpcomingRenewals
{
    public function within(CarbonImmutable $now, int $days = 30): UpcomingRenewalWindow
    {
        $renewals = Renewal::query()
            ->whereBetween('renews_at', [$now->startOfDay(), $now->copy()->addDays($days)->endOfDay()])
            ->whereHas('costItem', fn ($query) => $query
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', $now))
            ->with(['costItem.services.providerAccount'])
            ->orderBy('renews_at')
            ->orderBy('id')
            ->get();

        $rows = $renewals->map(fn (Renewal $renewal): array => $this->row($renewal));

        $totals = [];

        foreach ($rows as $row) {
            $money = $row['amount'];

            if ($money === null) {
                continue;
            }

            $totals[$money->currency] = ($totals[$money->currency] ?? 0) + $money->amountMinor;
        }

        return new UpcomingRenewalWindow(
            array_values($rows->all()),
            $totals,
            $rows->filter(fn (array $row): bool => $row['amount'] === null)->count(),
        );
    }

    /**
     * @return array{name: string, provider: string, amount: ?Money, renews_at: CarbonImmutable, auto_renew: bool, cost_item: CostItem}
     */
    private function row(Renewal $renewal): array
    {
        $item = $renewal->costItem;
        $service = $item->services->first();
        $providerAccount = $service !== null ? $service->providerAccount : null;
        $providerName = $providerAccount !== null ? $providerAccount->display_name : null;

        return [
            'name' => $service !== null ? $service->name : __('Unnamed charge'),
            'provider' => $providerName !== null && $providerName !== '' ? $providerName : __('Manual'),
            'amount' => $item->money(),
            'renews_at' => $renewal->renews_at,
            'auto_renew' => $renewal->auto_renew,
            'cost_item' => $item,
        ];
    }
}
