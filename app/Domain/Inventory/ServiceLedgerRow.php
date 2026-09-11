<?php

namespace App\Domain\Inventory;

use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Projection\ChargeLine;
use App\Domain\Inventory\Models\Service;
use App\Domain\Support\ValueObjects\Money;
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
        public ?int $manualChargeId,
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

        return $this->provider === ChargeLine::MANUAL_PROVIDER ? 'manual' : 'synced';
    }
}
