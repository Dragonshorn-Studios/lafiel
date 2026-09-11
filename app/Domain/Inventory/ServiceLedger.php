<?php

namespace App\Domain\Inventory;

use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Inventory\Models\Service;
use App\Domain\Support\ValueObjects\Money;
use App\Domain\Support\ValueObjects\Rational;
use Carbon\CarbonImmutable;

/**
 * One row per service for the Services view: the service identity plus
 * a roll-up of its open winning charges — monthly equivalent summed as
 * an exact rational per currency and rounded once per row, unknown and
 * stale charges counted rather than hidden, and a package flag when any
 * charge covers more than one service. Source-level detail lives on the
 * service's detail view; the table never sums money by itself.
 */
final readonly class ServiceLedgerRow
{
    /**
     * @param  list<array{period: string, amount: ?Money, evidence: EvidenceState, source_ref: ?string}>  $charges
     */
    public function __construct(
        public Service $service,
        public string $provider,
        public int $chargeCount,
        public ?string $billing,
        public ?Money $sourceAmount,
        public ?int $monthlyMinor,
        public ?string $monthlyCurrency,
        public int $unknownCount,
        public int $staleCount,
        public bool $package,
        public ?CarbonImmutable $renewsAt,
        public ?bool $autoRenew,
        public array $charges,
        public ?int $manualChargeId = null,
    ) {}

    /**
     * Freshness for the table: how the row's evidence ages. Manual
     * charges never go stale, so a purely manual service reads Manual.
     */
    public function freshness(): string
    {
        if ($this->staleCount > 0) {
            return 'stale';
        }

        return $this->provider === 'Manual' ? 'manual' : 'synced';
    }
}

/**
 * Read model behind the Services table. Winners come from the
 * projector, so evidence selection and equivalents are computed in
 * exactly one place.
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
            ->with(['providerAccount', 'costItems.renewal'])
            ->orderBy('name')
            ->get();

        $winners = $this->projector->winners($now);

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
            $rows[] = $this->row($service, $chargesByService[$service->id], $now);
        }

        return $rows;
    }

    /**
     * @param  list<CostItem>  $charges
     */
    private function row(Service $service, array $charges, CarbonImmutable $now): ServiceLedgerRow
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
                // unknown too: it cannot be normalized to a month.
                $unknownCount++;
            } elseif ($monthlyCurrency !== null && $monthlyCurrency !== $amount->currency) {
                // Never mix currencies inside one row's equivalent.
                $mixedCurrency = true;
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
            provider: $providerName !== null && $providerName !== '' ? $providerName : 'Manual',
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
