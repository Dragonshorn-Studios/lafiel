<?php

namespace App\Domain\Providers\Contabo;

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Providers\Contracts\ProviderAdapter;
use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Dtos\CostFact;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\CredentialCheck;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;

/**
 * Read-only Contabo adapter for inventory. Compute instances and
 * Object Storage are discovered by their stable ids; Storage VPS is
 * unsupported by the Compute API and is never seen here.
 *
 * Contabo exposes no dependable billing surface, so every discovered
 * resource gets one recurring cost fact with an unknown amount: the
 * charge demonstrably exists, its price is honestly unknown and never
 * inferred from product listings. A manual price attached to the
 * service (the overlay on stable identity) is the price path until a
 * real billing surface is proven by a spike.
 */
final class ContaboProviderAdapter implements ProviderAdapter
{
    private const PER_PAGE = 100;

    /** A misbehaving total must not spin the walk forever. */
    private const MAX_PAGES = 50;

    public function __construct(private readonly BuildContaboApi $buildApi) {}

    public function validateCredentials(SyncContext $context): CredentialCheck
    {
        try {
            $this->buildApi->build($context->credentials)->get('/v1/compute/instances', ['size' => 1]);
        } catch (InvalidCredentialsException $exception) {
            return CredentialCheck::invalid($exception->getMessage());
        }

        return CredentialCheck::valid();
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::Subscriptions,
        ]);
    }

    public function fetchInventory(SyncContext $context): InventoryBatch
    {
        $api = $this->buildApi->build($context->credentials);
        $warnings = [];
        $items = [];
        $partial = false;

        // The compute listing is the primary observation: its failure
        // fails the whole phase (the exception propagates), keeping the
        // last good data — the same rule as OVH's service listing.
        [$computeInstances, $computeTruncated] = $this->collect($api, '/v1/compute/instances', $warnings);
        $partial = $computeTruncated;

        foreach ($computeInstances as $instance) {
            $item = $this->instanceItem($instance);

            if ($item === null) {
                $partial = true;
                $warnings[] = 'a compute instance without an id or name was skipped.';

                continue;
            }

            $items[] = $item;
        }

        try {
            [$storageInstances, $storageTruncated] = $this->collect($api, '/v1/object-storage/instances', $warnings);
            $partial = $partial || $storageTruncated;

            foreach ($storageInstances as $instance) {
                $item = $this->storageItem($instance);

                if ($item === null) {
                    $partial = true;
                    $warnings[] = 'an object storage instance without an id or name was skipped.';

                    continue;
                }

                $items[] = $item;
            }
        } catch (InvalidCredentialsException $exception) {
            throw $exception;
        } catch (ProviderException $exception) {
            // Object storage failing alone degrades the batch — compute
            // data stays good and must not be thrown away.
            $partial = true;
            $warnings[] = 'object storage listing failed: '.$exception->getMessage();
        }

        return new InventoryBatch(
            completeness: $partial ? BatchCompleteness::Partial : BatchCompleteness::Complete,
            observedAt: $context->now,
            sourceRef: 'contabo:/v1/compute/instances,/v1/object-storage/instances',
            items: $items,
            warnings: $warnings,
        );
    }

    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $facts = [];

        foreach ($inventory->items as $item) {
            $facts[] = new CostFact(
                sourceRef: sprintf('contabo:resource:%s', $item->externalId),
                serviceExternalIds: [$item->externalId],
                sourceKind: SourceKind::Subscription,
                chargeKind: ChargeKind::RecurringFixed,
                period: Period::Monthly,
                evidenceState: EvidenceState::Estimate,
                amount: null,
                validFrom: $context->now->startOfDay(),
                taxBasis: TaxBasis::Unknown,
                allocationState: AllocationState::Direct,
                notes: 'Recurring charge asserted by inventory; Contabo exposes no price API.',
            );
        }

        return new CostFactBatch(
            completeness: BatchCompleteness::Complete,
            observedAt: $context->now,
            sourceRef: 'contabo:inventory-subscriptions',
            facts: $facts,
            warnings: ['no Contabo billing API — every price is unknown until a manual price is attached to the service.'],
            capabilityCompleteness: [
                // The recurring charge per resource is fully observed;
                // only its price is unknown, which is an amount state,
                // not a completeness state.
                ProviderCapability::Subscriptions->value => BatchCompleteness::Complete,
            ],
            reportedCapabilities: [ProviderCapability::Subscriptions],
        );
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private function instanceItem(array $instance): ?InventoryItem
    {
        $externalId = (string) ($instance['instanceId'] ?? '');
        $name = (string) ($instance['displayName'] ?? '');

        if ($externalId === '' || $name === '') {
            return null;
        }

        return new InventoryItem(
            externalId: $externalId,
            category: 'compute',
            name: $name,
            providerType: isset($instance['productType']) && is_string($instance['productType']) ? $instance['productType'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private function storageItem(array $instance): ?InventoryItem
    {
        $externalId = (string) ($instance['objectStorageId'] ?? '');
        $name = (string) ($instance['displayName'] ?? '');

        if ($externalId === '' || $name === '') {
            return null;
        }

        return new InventoryItem(
            externalId: $externalId,
            category: 'storage',
            name: $name,
            providerType: 'object_storage',
        );
    }

    /**
     * Every page of one Contabo collection. `_metadata.totalCount`
     * names the full size; a missing or contradictory count ends the
     * walk after the current page, and the hard cap keeps a misbehaving
     * total from spinning forever.
     *
     * @param  list<string>  $warnings
     * @return array{0: list<array<string, mixed>>, 1: bool} the entries
     *                                                       plus whether the walk was cut short
     */
    private function collect(ContaboApi $api, string $path, array &$warnings): array
    {
        $items = [];
        $truncated = false;
        $page = 1;
        $totalPages = 1;

        do {
            $body = $api->get($path, ['size' => self::PER_PAGE, 'page' => $page]);

            $data = $body['data'] ?? [];

            if (is_array($data)) {
                foreach ($data as $entry) {
                    if (is_array($entry)) {
                        $items[] = $entry;
                    }
                }
            }

            $metadata = is_array($body['_metadata'] ?? null) ? $body['_metadata'] : [];

            if (isset($metadata['totalCount']) && is_numeric($metadata['totalCount'])) {
                $totalCount = (int) $metadata['totalCount'];
                $totalPages = (int) ceil($totalCount / self::PER_PAGE);
            } else {
                // No readable total: walking on would be a guess. Stop
                // here, but say so — silent truncation would leave the
                // batch complete-looking while resources are lost.
                $totalPages = $page;
                $truncated = true;
                $warnings[] = sprintf('listing [%s] gave no readable total count; stopped after page %d.', $path, $page);
            }

            $page++;
        } while ($page <= min($totalPages, self::MAX_PAGES));

        if ($totalPages > self::MAX_PAGES) {
            $warnings[] = sprintf('listing [%s] has more than %d pages; the rest was not read.', $path, self::MAX_PAGES);
            $truncated = true;
        }

        return [$items, $truncated];
    }
}
