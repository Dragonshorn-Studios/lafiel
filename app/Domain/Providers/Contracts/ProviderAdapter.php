<?php

namespace App\Domain\Providers\Contracts;

use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\CredentialCheck;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\SyncContext;

/**
 * One provider adapter. Adapters return canonical DTO batches and never
 * persist Eloquent models directly — the application layer validates,
 * persists, and applies lifecycle. Failures are signalled with typed
 * exceptions: TransientProviderException for 429/5xx/timeout,
 * InvalidCredentialsException for rejected credentials.
 */
interface ProviderAdapter
{
    public function validateCredentials(SyncContext $context): CredentialCheck;

    public function capabilities(): CapabilitySet;

    public function fetchInventory(SyncContext $context): InventoryBatch;

    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch;
}
