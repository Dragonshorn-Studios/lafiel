<?php

namespace App\Domain\Providers\Dtos;

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
 * price is unknown, never inferred.
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
    ) {}
}
