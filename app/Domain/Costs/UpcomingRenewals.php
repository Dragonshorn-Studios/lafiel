<?php

namespace App\Domain\Costs;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Costs\Projection\ChargeLine;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;

/**
 * Read model for the renewal window: every renewal attached to a still
 * open charge, renews within the given number of days. The Overview
 * renders what this returns — service, provider, amount, date — and
 * the Overview never sums money on its own: the per-currency totals
 * here are plain minor-unit sums of the un-normalized source amounts
 * (an annual renewal shows its annual price).
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

        return UpcomingRenewalWindow::fromRows(array_values($rows->all()));
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
            'provider' => $providerName !== null && $providerName !== '' ? $providerName : ChargeLine::MANUAL_PROVIDER,
            'amount' => $item->money(),
            'renews_at' => $renewal->renews_at,
            'auto_renew' => $renewal->auto_renew,
            'cost_item' => $item,
        ];
    }
}
