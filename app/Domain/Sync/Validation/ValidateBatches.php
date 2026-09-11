<?php

namespace App\Domain\Sync\Validation;

use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Sync\Exceptions\InvalidBatchException;

/**
 * Canonical batch invariants, checked before anything is persisted.
 * Messages name the offending source reference only — never payloads
 * or credential material.
 */
final class ValidateBatches
{
    /** Canonical service categories (docs/integrations.md). */
    public const CATEGORIES = [
        'compute', 'storage', 'network', 'domain', 'dns', 'database',
        'observability', 'security', 'email', 'saas', 'other',
    ];

    /**
     * @throws InvalidBatchException
     */
    public function inventory(InventoryBatch $batch): void
    {
        $this->requireSourceRef($batch->sourceRef, 'inventory');

        $seen = [];

        foreach ($batch->items as $item) {
            if ($item->externalId === '') {
                throw new InvalidBatchException('Inventory batch contains an item without an external id.');
            }

            if ($item->name === '') {
                throw new InvalidBatchException("Inventory item [{$item->externalId}] has no name.");
            }

            if (! in_array($item->category, self::CATEGORIES, true)) {
                throw new InvalidBatchException("Inventory item [{$item->externalId}] has a non-canonical category [{$item->category}].");
            }

            if (isset($seen[$item->externalId])) {
                throw new InvalidBatchException("Inventory batch contains duplicate external id [{$item->externalId}].");
            }

            $seen[$item->externalId] = true;
        }
    }

    /**
     * Every cost fact must reference services from the same run's
     * inventory: one batch is one observation, and facts pointing at
     * unseen resources would silently widen it.
     *
     * @throws InvalidBatchException
     */
    public function costFacts(CostFactBatch $batch, InventoryBatch $inventory): void
    {
        $this->requireSourceRef($batch->sourceRef, 'cost facts');

        $known = [];
        foreach ($inventory->items as $item) {
            $known[$item->externalId] = true;
        }

        foreach ($batch->facts as $fact) {
            if ($fact->sourceRef === '') {
                throw new InvalidBatchException('Cost fact batch contains a fact without a source reference.');
            }

            if ($fact->serviceExternalIds === []) {
                throw new InvalidBatchException("Cost fact [{$fact->sourceRef}] covers no service.");
            }

            foreach ($fact->serviceExternalIds as $externalId) {
                if (! isset($known[$externalId])) {
                    throw new InvalidBatchException("Cost fact [{$fact->sourceRef}] references service [{$externalId}] that is not part of this run's inventory.");
                }
            }
        }
    }

    /**
     * @throws InvalidBatchException
     */
    private function requireSourceRef(string $sourceRef, string $phase): void
    {
        if ($sourceRef === '') {
            throw new InvalidBatchException("{$phase} batch has no stable source reference.");
        }
    }
}
