<?php

namespace App\Domain\Sync\Persistence;

use App\Domain\Inventory\Models\Service;

/**
 * Result of persisting one inventory batch.
 */
final readonly class PersistedInventory
{
    /**
     * @param  array<string, Service>  $services  external id => persisted service
     */
    public function __construct(
        public array $services,
        public int $created,
        public int $updated,
    ) {}
}
