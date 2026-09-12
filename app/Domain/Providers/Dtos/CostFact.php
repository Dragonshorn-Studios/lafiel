<?php

namespace App\Domain\Providers\Dtos;

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;

/**
 * One canonical cost fact. A fact may cover one or many services (a
 * package charge covers them all through one cost item). A missing
 * price is unknown, never inferred. Allocation is `direct` when the
 * price belongs to the covered services as stated and
 * `shared_unallocated` when a package price part cannot be attributed
 * — uncertainty is counted, never double-counted.
 *
 * `$notes` preserves provider-side descriptive text (a rate plan, a
 * VAT note) that is not part of the price identity; it never
 * participates in dedupe or precedence.
 */
final readonly class CostFact
{
    /**
     * @param  list<string>  $serviceExternalIds
     */
    public function __construct(
        public string $sourceRef,
        public array $serviceExternalIds,
        public SourceKind $sourceKind,
        public ChargeKind $chargeKind,
        public Period $period,
        public EvidenceState $evidenceState,
        public ?Money $amount,
        public CarbonImmutable $validFrom,
        public TaxBasis $taxBasis = TaxBasis::Unknown,
        public ?CarbonImmutable $renewsAt = null,
        public bool $autoRenew = false,
        public AllocationState $allocationState = AllocationState::Direct,
        public ?string $notes = null,
    ) {}
}
