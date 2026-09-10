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
 * and annual equivalents.
 */
final readonly class ChargeLine
{
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
        );
    }
}
