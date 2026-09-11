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
     * @param  array<string, BatchCompleteness>  $capabilityCompleteness
     */
    public function __construct(
        public BatchCompleteness $completeness,
        public CarbonImmutable $observedAt,
        public string $sourceRef,
        public array $facts,
        public array $warnings = [],
        public array $capabilityCompleteness = [],
    ) {}

    /**
     * Completeness for one cost capability: the explicit per-capability
     * entry when the adapter declared one, otherwise the phase result.
     */
    public function completenessFor(ProviderCapability $capability): BatchCompleteness
    {
        return $this->capabilityCompleteness[$capability->value] ?? $this->completeness;
    }
}
