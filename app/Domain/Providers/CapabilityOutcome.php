<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use Carbon\CarbonImmutable;

/**
 * Outcome of one capability during one sync run.
 */
final readonly class CapabilityOutcome
{
    public function __construct(
        public ProviderCapability $capability,
        public bool $attempted,
        public ?BatchCompleteness $completeness = null,
        public ?CarbonImmutable $observedAt = null,
    ) {}
}
