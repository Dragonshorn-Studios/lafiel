<?php

namespace App\Domain\Sync\Persistence;

/**
 * Result of persisting one cost-fact batch.
 */
final readonly class PersistedCostFacts
{
    public function __construct(
        public int $created,
        public int $superseded,
        public int $updated,
        public int $renewals,
    ) {}
}
