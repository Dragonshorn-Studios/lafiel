<?php

namespace App\Domain\Costs\Projection;

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\AmountState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Support\ValueObjects\Rational;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aggregates active cost items into monthly and annual equivalents.
 *
 * Invariants (Architecture v2):
 * - aggregate cost items, never service rows: a package charge that
 *   covers many services appears exactly once;
 * - for one logical charge only the strongest evidence counts —
 *   invoice actual > usage actual > subscription or renewal quote >
 *   manual, with a conscious manual override above all — and actual
 *   replaces a quote instead of being added to it;
 * - unknown amounts never join the known totals, they are counted;
 * - one-time charges stay outside recurring spend;
 * - sums stay exact rationals and are rounded once, half-even;
 * - totals are per currency, never silently converted.
 */
class CostProjector
{
    public function project(CarbonImmutable $onDate): ProjectionResult
    {
        $winners = $this->winners($onDate);
        $suppressed = $this->overriddenUnknowns($winners);

        /** @var array<string, array{monthly: Rational, annual: Rational, oneTime: int, lines: list<ChargeLine>}> $accumulators */
        $accumulators = [];
        $unknownCount = 0;
        $estimateCount = 0;
        $staleCount = 0;
        $sharedUnallocatedCount = 0;
        $oneTimeCount = 0;

        foreach ($winners as $key => $item) {
            $amount = $item->money();

            if ($item->amount_state === AmountState::Unknown || $amount === null) {
                // An unknown whose services all carry a conscious manual
                // override has been answered — it no longer counts.
                if (! isset($suppressed[$key])) {
                    $unknownCount++;
                }

                continue;
            }

            $isStale = $this->isStale($item, $onDate);
            $isEstimate = $item->evidence_state === EvidenceState::Estimate;

            if ($isStale) {
                $staleCount++;
            }

            if ($isEstimate) {
                $estimateCount++;
            }

            if ($item->allocation_state === AllocationState::SharedUnallocated) {
                $sharedUnallocatedCount++;
            }

            $isOneTime = $item->charge_kind === ChargeKind::OneTime
                || $item->period === Period::OneTime;

            if ($isOneTime) {
                $oneTimeCount++;
            }

            $accumulator = $accumulators[$amount->currency]
                ?? ['monthly' => new Rational(0), 'annual' => new Rational(0), 'oneTime' => 0, 'lines' => []];

            if ($isOneTime) {
                $accumulator['oneTime'] += $amount->amountMinor;
                $monthly = $amount->toRational();
                $annual = $amount->toRational();
            } elseif ($item->period === Period::Unknown) {
                // A known amount whose recurrence is unknown cannot join a
                // monthly or annual total; it stays counted as unknown and
                // does not materialize an empty currency total.
                $unknownCount++;

                continue;
            } else {
                $monthly = $item->period->monthlyEquivalent($amount);
                $annual = $item->period->annualEquivalent($amount);

                $accumulator['monthly'] = $accumulator['monthly']->add($monthly);
                $accumulator['annual'] = $accumulator['annual']->add($annual);
            }

            $accumulator['lines'][] = ChargeLine::fromCostItem(
                $item,
                $amount,
                $monthly ?? $amount->toRational(),
                $annual ?? $amount->toRational(),
                $isStale,
            );

            $accumulators[$amount->currency] = $accumulator;
        }

        $byCurrency = [];

        foreach ($accumulators as $currency => $accumulator) {
            $byCurrency[$currency] = CurrencyTotal::fromAccumulator($currency, $accumulator);
        }

        ksort($byCurrency);

        return new ProjectionResult(
            calculationVersion: (string) config('costs.calculation_version', 'v1'),
            byCurrency: $byCurrency,
            unknownCount: $unknownCount,
            estimateCount: $estimateCount,
            staleCount: $staleCount,
            sharedUnallocatedCount: $sharedUnallocatedCount,
            oneTimeCount: $oneTimeCount,
        );
    }

    /**
     * Winning synced charges whose unknown price a conscious manual
     * override has answered: every service the charge covers also
     * carries an open manual override, so the provider's "unknown" is
     * no longer the best known price for any of them. The charge
     * itself stays — history and provenance are untouched — but it no
     * longer counts toward the incompleteness totals.
     *
     * @param  array<string, CostItem>  $winners
     * @return array<string, true> suppressed winners by logical charge key
     */
    public function overriddenUnknowns(array $winners): array
    {
        $overrideCovered = [];

        foreach ($winners as $winner) {
            if ($winner->source_kind === SourceKind::Manual && $winner->is_manual_override) {
                foreach ($winner->services as $service) {
                    $overrideCovered[$service->id] = true;
                }
            }
        }

        if ($overrideCovered === []) {
            return [];
        }

        $suppressed = [];

        foreach ($winners as $key => $winner) {
            if ($winner->source_kind === SourceKind::Manual || $winner->money() !== null) {
                continue;
            }

            $services = $winner->services;

            if ($services->isEmpty()) {
                continue;
            }

            foreach ($services as $service) {
                if (! isset($overrideCovered[$service->id])) {
                    continue 2;
                }
            }

            $suppressed[$key] = true;
        }

        return $suppressed;
    }

    /**
     * The winning cost item per logical charge that is open on the given
     * date — including unknown-amount charges, which project() only
     * counts. Read models build service-centric views on top of this
     * instead of re-implementing evidence selection.
     *
     * @return array<string, CostItem>
     */
    public function winners(CarbonImmutable $onDate): array
    {
        $items = CostItem::query()
            ->with(['services.providerAccount', 'renewal'])
            ->whereDate('valid_from', '<=', $onDate)
            ->where(fn ($query) => $query
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', $onDate))
            ->orderBy('id')
            ->get();

        return $this->pickWinners($items);
    }

    /**
     * Stale evidence: synced observations older than the freshness window.
     * Manual evidence has no provider observation behind it and never
     * goes stale.
     */
    public function staleFor(CostItem $item, CarbonImmutable $onDate): bool
    {
        return $this->isStale($item, $onDate);
    }

    /**
     * Group competing evidence by logical charge and keep the winner.
     *
     * @param  Collection<int, CostItem>  $items
     * @return array<string, CostItem>
     */
    private function pickWinners(Collection $items): array
    {
        $winners = [];

        foreach ($items as $item) {
            $key = $item->logical_charge_key;

            if (! isset($winners[$key]) || $item->outranks($winners[$key])) {
                $winners[$key] = $item;
            }
        }

        return $winners;
    }

    /**
     * Stale evidence: synced observations older than the freshness window.
     * Manual evidence has no provider observation behind it and never
     * goes stale.
     */
    private function isStale(CostItem $item, CarbonImmutable $onDate): bool
    {
        if ($item->source_kind === SourceKind::Manual) {
            return false;
        }

        if ($item->observed_at === null) {
            return true;
        }

        $freshnessDays = (int) config('costs.freshness_days', 7);

        return $item->observed_at->lt($onDate->subDays($freshnessDays));
    }
}
