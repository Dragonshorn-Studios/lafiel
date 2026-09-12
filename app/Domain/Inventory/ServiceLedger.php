<?php

namespace App\Domain\Inventory;

use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\ChargeLine;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Inventory\Models\Service;
use App\Domain\Support\ValueObjects\Rational;
use Carbon\CarbonImmutable;

/**
 * Read model behind the Services table. Winners come from the
 * projector, so evidence selection and equivalents are computed in
 * exactly one place. One row per service — manual and
 * provider-discovered — with a roll-up of its open winning charges;
 * the table never sums money by itself.
 */
class ServiceLedger
{
    public function __construct(private readonly CostProjector $projector) {}

    /**
     * @return list<ServiceLedgerRow>
     */
    public function rows(CarbonImmutable $now): array
    {
        $services = Service::query()
            ->with('providerAccount')
            ->orderBy('name')
            ->get();

        $winners = $this->projector->winners($now);
        $suppressed = $this->projector->overriddenUnknowns($winners);

        // The open winning charges behind each service. A package
        // charge appears under every service it covers, but the
        // equivalent math below runs per service — the projection's
        // counted-once property is untouched.
        $chargesByService = [];

        foreach ($services as $service) {
            $chargesByService[$service->id] = [];
        }

        foreach ($winners as $winner) {
            foreach ($winner->services as $service) {
                if (isset($chargesByService[$service->id])) {
                    $chargesByService[$service->id][] = $winner;
                }
            }
        }

        $rows = [];

        foreach ($services as $service) {
            $rows[] = $this->row($service, $chargesByService[$service->id], $now, $suppressed);
        }

        return $rows;
    }

    /**
     * @param  list<CostItem>  $charges
     * @param  array<string, true>  $suppressed  overridden unknowns (see
     *                                           CostProjector::overriddenUnknowns) — kept in the charge
     *                                           views, excluded from the unknown badge
     */
    private function row(Service $service, array $charges, CarbonImmutable $now, array $suppressed): ServiceLedgerRow
    {
        $providerName = $service->providerAccount?->display_name;

        $monthly = new Rational(0);
        $monthlyCurrency = null;
        $mixedCurrency = false;
        $hasPriced = false;
        $unknownCount = 0;
        $staleCount = 0;
        $package = false;
        $chargeViews = [];
        $billing = null;
        $sourceAmount = null;
        $renewsAt = null;
        $autoRenew = null;
        $manualChargeIds = [];

        foreach ($charges as $charge) {
            if ($charge->source_kind === SourceKind::Manual) {
                $manualChargeIds[] = $charge->id;
            }

            $amount = $charge->money();
            $monthlyEquivalent = $amount === null ? null : $charge->period->monthlyEquivalent($amount);

            if ($this->projector->staleFor($charge, $now)) {
                $staleCount++;
            }

            if ($amount === null || $monthlyEquivalent === null) {
                // Known amount with an unknown cadence counts as
                // unknown too — including one-time charges, which have
                // no monthly equivalent by definition. A charge whose
                // unknown was answered by a conscious manual override
                // is kept as provenance but stops counting.
                if (! isset($suppressed[$charge->logical_charge_key])) {
                    $unknownCount++;
                }
            } elseif ($monthlyCurrency !== null && $monthlyCurrency !== $amount->currency) {
                // Never mix currencies inside one row's equivalent; the
                // un-mixable charge is counted as unknown instead of
                // silently vanishing from the badges.
                $mixedCurrency = true;
                $unknownCount++;
            } else {
                $monthlyCurrency ??= $amount->currency;
                $monthly = $monthly->add($monthlyEquivalent);
                $hasPriced = true;
            }

            if ($charge->services->count() > 1) {
                $package = true;
            }

            $renewsAt ??= $charge->renewal?->renews_at;
            $autoRenew ??= $charge->renewal?->auto_renew;

            $billing ??= $charge->period->value;
            $sourceAmount ??= $amount;

            $chargeViews[] = [
                'period' => $charge->period->value,
                'amount' => $amount,
                'evidence' => $charge->evidence_state,
                'source_ref' => $charge->source_ref,
            ];
        }

        $priced = $hasPriced && ! $mixedCurrency;

        // Row-level edit actions need one unambiguous manual charge.
        $manualChargeId = count($manualChargeIds) === 1 ? $manualChargeIds[0] : null;

        return new ServiceLedgerRow(
            service: $service,
            provider: $providerName !== null && $providerName !== '' ? $providerName : ChargeLine::MANUAL_PROVIDER,
            chargeCount: count($charges),
            billing: $billing,
            sourceAmount: $sourceAmount,
            monthlyMinor: $priced ? $monthly->roundHalfEven() : null,
            monthlyCurrency: $priced ? $monthlyCurrency : null,
            unknownCount: $unknownCount,
            staleCount: $staleCount,
            package: $package,
            renewsAt: $renewsAt,
            autoRenew: $autoRenew,
            charges: $chargeViews,
            manualChargeId: $manualChargeId,
        );
    }
}
