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
        'account', 'compute', 'storage', 'network', 'domain', 'dns',
        'database', 'observability', 'security', 'email', 'saas', 'other',
    ];

    /**
     * @throws InvalidBatchException
     */
    public function inventory(InventoryBatch $batch): void
    {
        $this->requireSourceRef($batch->sourceRef, 'inventory');

        // A batch that saw nothing must carry nothing: the completeness
        // flag drives run status, lifecycle, and charge ending, so a
        // contradiction here would silently steer all three.
        if (! $batch->completeness->producedUsableData() && $batch->items !== []) {
            throw new InvalidBatchException(
                "Inventory batch is marked [{$batch->completeness->value}] but carries items.",
            );
        }

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

        if (! $batch->completeness->producedUsableData() && $batch->facts !== []) {
            throw new InvalidBatchException(
                "Cost fact batch is marked [{$batch->completeness->value}] but carries facts.",
            );
        }

        $known = [];
        foreach ($inventory->items as $item) {
            $known[$item->externalId] = true;
        }

        $seenRefs = [];

        foreach ($batch->facts as $fact) {
            if ($fact->sourceRef === '') {
                throw new InvalidBatchException('Cost fact batch contains a fact without a source reference.');
            }

            if (isset($seenRefs[$fact->sourceRef])) {
                throw new InvalidBatchException("Cost fact batch contains duplicate source reference [{$fact->sourceRef}].");
            }

            if ($fact->serviceExternalIds === []) {
                throw new InvalidBatchException("Cost fact [{$fact->sourceRef}] covers no service.");
            }

            foreach ($fact->serviceExternalIds as $externalId) {
                if (! isset($known[$externalId])) {
                    throw new InvalidBatchException("Cost fact [{$fact->sourceRef}] references service [{$externalId}] that is not part of this run's inventory.");
                }
            }

            $seenRefs[$fact->sourceRef] = true;
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
