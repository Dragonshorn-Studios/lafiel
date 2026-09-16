<?php

namespace App\Domain\Sync\Persistence;

use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Dtos\InventoryBatch;

/**
 * Idempotent upsert of one inventory batch. Services are keyed by
 * `(provider_account_id, external_id)`; descriptive fields update only
 * when they actually changed, so an identical re-sync leaves rows (and
 * their updated_at) untouched. Seen timestamps are set on creation
 * only — ongoing seen/missing transitions are lifecycle's job.
 */
final class PersistInventoryBatch
{
    public function persist(int $accountId, InventoryBatch $batch): PersistedInventory
    {
        $created = 0;
        $updated = 0;
        $services = [];

        foreach ($batch->items as $item) {
            $service = Service::query()->firstOrNew([
                'provider_account_id' => $accountId,
                'external_id' => $item->externalId,
            ]);
            $isNew = ! $service->exists;

            if ($isNew) {
                $service->lifecycle_state = ServiceLifecycle::Active;
                $service->first_seen_at = $batch->observedAt;
                $service->last_seen_at = $batch->observedAt;
            }

            $service->category = $item->category;
            $service->name = $item->name;
            $service->provider_type = $item->providerType;
            $service->url = $item->url;
            $service->metadata = $item->metadata;

            if ($service->isDirty()) {
                $service->save();
            }

            $created += $isNew ? 1 : 0;
            $updated += (! $isNew && $service->wasChanged()) ? 1 : 0;
            $services[$item->externalId] = $service;
        }

        return new PersistedInventory($services, $created, $updated);
    }
}
