<?php

namespace App\Domain\Providers\Dtos;

use App\Domain\Providers\Enums\BatchCompleteness;
use Carbon\CarbonImmutable;

/**
 * One provider inventory observation. Carries completeness, observation
 * time, sanitized warnings, and a stable source reference; it never
 * carries raw payloads.
 */
final readonly class InventoryBatch
{
    /**
     * @param  list<InventoryItem>  $items
     * @param  list<string>  $warnings
     */
    public function __construct(
        public BatchCompleteness $completeness,
        public CarbonImmutable $observedAt,
        public string $sourceRef,
        public array $items,
        public array $warnings = [],
    ) {}
}
