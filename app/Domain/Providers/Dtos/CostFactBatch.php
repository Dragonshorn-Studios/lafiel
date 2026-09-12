<?php

namespace App\Domain\Providers\Dtos;

use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use Carbon\CarbonImmutable;

/**
 * All cost evidence observed in one run. Carries the overall phase
 * completeness plus an optional per-capability refinement, so one
 * adapter call can report subscriptions complete while usage failed —
 * each capability keeps its own freshness.
 */
final readonly class CostFactBatch
{
    /**
     * @param  list<CostFact>  $facts
     * @param  list<string>  $warnings
     * @param  array<string, BatchCompleteness>  $capabilityCompleteness  keyed by capability value
     * @param  list<ProviderCapability>  $reportedCapabilities  the capabilities whose
     *                                                          observation this batch represents — even when it observed none.
     *                                                          Charge ending is gated per reported capability: a capability
     *                                                          this batch does not represent (an always-partial usage feed)
     *                                                          cannot undercut the evidence of one it does
     */
    public function __construct(
        public BatchCompleteness $completeness,
        public CarbonImmutable $observedAt,
        public string $sourceRef,
        public array $facts,
        public array $warnings = [],
        public array $capabilityCompleteness = [],
        public array $reportedCapabilities = [],
    ) {
        foreach ($capabilityCompleteness as $capability => $completeness) {
            if (ProviderCapability::tryFrom((string) $capability) === null) {
                throw new \InvalidArgumentException("Unknown capability [{$capability}] in per-capability completeness.");
            }

            if (! in_array($completeness, BatchCompleteness::cases(), true)) {
                throw new \InvalidArgumentException("Invalid completeness for capability [{$capability}].");
            }
        }

        foreach ($reportedCapabilities as $capability) {
            if (! in_array($capability, ProviderCapability::cases(), true)) {
                throw new \InvalidArgumentException('Reported capabilities must be ProviderCapability instances.');
            }
        }
    }

    /**
     * Completeness for one cost capability: the explicit per-capability
     * entry when the adapter declared one, otherwise the phase result.
     */
    public function completenessFor(ProviderCapability $capability): BatchCompleteness
    {
        return $this->capabilityCompleteness[$capability->value] ?? $this->completeness;
    }
}
