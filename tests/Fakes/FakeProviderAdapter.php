<?php

namespace Tests\Fakes;

use App\Domain\Providers\Contracts\ProviderAdapter;
use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\CredentialCheck;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use Throwable;

/**
 * Scriptable adapter for orchestration tests: returns the configured
 * batches, throws from its exception queues (one per call), and counts
 * every fetch and credential call so tests can assert retry and
 * validation behavior. It implements the full adapter contract.
 */
final class FakeProviderAdapter implements ProviderAdapter
{
    public int $validateCalls = 0;

    public int $inventoryCalls = 0;

    public int $costCalls = 0;

    /** @var list<Throwable> */
    public array $validateExceptions = [];

    /** @var list<Throwable> */
    public array $inventoryExceptions = [];

    /** @var list<Throwable> */
    public array $costExceptions = [];

    public function __construct(
        public CredentialCheck $credentialCheck = new CredentialCheck(true),
        public CapabilitySet $capabilitySet = new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::RenewalQuotes,
        ]),
        public ?InventoryBatch $inventoryBatch = null,
        public ?CostFactBatch $costFactBatch = null,
    ) {}

    public function validateCredentials(SyncContext $context): CredentialCheck
    {
        $this->validateCalls++;

        if ($exception = array_shift($this->validateExceptions)) {
            throw $exception;
        }

        return $this->credentialCheck;
    }

    public function capabilities(): CapabilitySet
    {
        return $this->capabilitySet;
    }

    public function fetchInventory(SyncContext $context): InventoryBatch
    {
        $this->inventoryCalls++;

        if ($exception = array_shift($this->inventoryExceptions)) {
            throw $exception;
        }

        return $this->inventoryBatch ?? new InventoryBatch(
            BatchCompleteness::Complete,
            $context->now,
            'fake:inventory',
            [],
        );
    }

    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $this->costCalls++;

        if ($exception = array_shift($this->costExceptions)) {
            throw $exception;
        }

        return $this->costFactBatch ?? new CostFactBatch(
            BatchCompleteness::Complete,
            $context->now,
            'fake:cost-facts',
            [],
        );
    }
}
