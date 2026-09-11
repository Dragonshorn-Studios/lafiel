<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use Carbon\CarbonImmutable;

/**
 * Outcome of one capability attempt during one sync run. Only attempted
 * capabilities get an outcome; null observedAt means the attempt
 * produced nothing observable (failed before data arrived).
 */
final readonly class CapabilityOutcome
{
    public function __construct(
        public ProviderCapability $capability,
        public BatchCompleteness $completeness,
        public ?CarbonImmutable $observedAt = null,
    ) {}
}
