<?php

namespace App\Domain\Costs\Projection;

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Support\ValueObjects\Money;
use App\Domain\Support\ValueObjects\Rational;

/**
 * One winning charge in the projection: the cost item that carries the
 * strongest evidence for its logical charge, converted to exact monthly
 * and annual equivalents. The provider label exists so read models can
 * group charges without touching Eloquent; a charge whose services
 * have no provider account is presented as Manual.
 */
final readonly class ChargeLine
{
    /**
     * Presentation label for charges with no provider account behind
     * them. Load-bearing: freshness and provider grouping compare
     * against it, so never pass it through translation.
     */
    public const MANUAL_PROVIDER = 'Manual';

    public function __construct(
        public int $costItemId,
        public string $logicalChargeKey,
        public SourceKind $sourceKind,
        public ChargeKind $chargeKind,
        public EvidenceState $evidenceState,
        public AllocationState $allocationState,
        public Money $amount,
        public Rational $monthlyEquivalent,
        public Rational $annualEquivalent,
        public bool $isStale,
        public string $provider = self::MANUAL_PROVIDER,
    ) {}

    public static function fromCostItem(CostItem $item, Money $amount, Rational $monthly, Rational $annual, bool $isStale): self
    {
        return new self(
            costItemId: $item->id,
            logicalChargeKey: $item->logical_charge_key,
            sourceKind: $item->source_kind,
            chargeKind: $item->charge_kind,
            evidenceState: $item->evidence_state,
            allocationState: $item->allocation_state,
            amount: $amount,
            monthlyEquivalent: $monthly,
            annualEquivalent: $annual,
            isStale: $isStale,
            provider: self::providerLabel($item),
        );
    }

    /**
     * The first provider account name behind the charge's services.
     * An overlay charge may span providers; v1 presents its first.
     */
    private static function providerLabel(CostItem $item): string
    {
        foreach ($item->services as $service) {
            $name = $service->providerAccount?->display_name;

            if ($name !== null && $name !== '') {
                return $name;
            }
        }

        return self::MANUAL_PROVIDER;
    }
}
