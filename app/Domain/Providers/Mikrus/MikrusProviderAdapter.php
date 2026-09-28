<?php

namespace App\Domain\Providers\Mikrus;

use App\Domain\Providers\Contracts\ProviderAdapter;
use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Dtos\CostFactBatch;
use App\Domain\Providers\Dtos\CredentialCheck;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\TransientProviderException;

/**
 * Read-only mikr.us adapter for inventory. Servers are discovered by
 * their stable names from `/serwery`, then enriched one by one from
 * `/info`; the expiration and service-tier fields go into
 * `services.metadata` (adapter-owned, overwritten wholesale each sync).
 *
 * mikr.us exposes no billing surface, so the adapter declares no cost
 * capability at all: no cost fact is ever produced, and a price
 * appears only as a manual charge covering the discovered service.
 * A price is never inferred from mikr.us's public plan list.
 */
final class MikrusProviderAdapter implements ProviderAdapter
{
    public function __construct(private readonly BuildMikrusApi $buildApi) {}

    public function validateCredentials(SyncContext $context): CredentialCheck
    {
        try {
            $this->buildApi->build($context->credentials)->post('/serwery');
        } catch (InvalidCredentialsException $exception) {
            return CredentialCheck::invalid($exception->getMessage());
        }

        return CredentialCheck::valid();
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet([
            ProviderCapability::Inventory,
        ]);
    }

    public function fetchInventory(SyncContext $context): InventoryBatch
    {
        $api = $this->buildApi->build($context->credentials);
        $warnings = [];
        $items = [];
        $partial = false;

        // The server listing is the primary observation: its failure
        // fails the whole phase (the exception propagates), keeping the
        // last good data — the same rule as Contabo's compute listing.
        $servers = $api->post('/serwery');

        // A 200 body that is a keyed map (mikr.us's error-object style)
        // would otherwise be walked as nameless entries and silently
        // empty the inventory observation. Classify it as transient.
        if ($servers !== [] && ! array_is_list($servers)) {
            throw new TransientProviderException('Mikr.us API returned an unreadable listing for [/serwery].');
        }

        foreach ($servers as $server) {
            $name = is_array($server) ? (string) ($server['name'] ?? '') : '';

            if ($name === '') {
                $partial = true;
                $warnings[] = 'a server entry without a name was skipped.';

                continue;
            }

            try {
                $info = $api->post('/info', ['srv' => $name]);
                $metadata = $this->metadataOf($info);

                if ($metadata === null) {
                    // A 200 body carrying none of the known fields is a
                    // shape anomaly: the adapter-owned column is about
                    // to be overwritten wholesale without them. Say so.
                    $warnings[] = sprintf('info for [%s] carried no known lifecycle fields; any stored metadata was discarded.', $name);
                }
            } catch (InvalidCredentialsException $exception) {
                throw $exception;
            } catch (ProviderException $exception) {
                // One server's info failing alone degrades the batch —
                // the discovery itself stays good and must not be
                // thrown away.
                $metadata = null;
                $partial = true;
                $warnings[] = sprintf('info for [%s] failed: %s', $name, $exception->getMessage());
            }

            $items[] = new InventoryItem(
                externalId: $name,
                category: 'compute',
                name: $name,
                providerType: isset($server['virtualization']) && is_string($server['virtualization']) ? $server['virtualization'] : null,
                metadata: $metadata,
            );
        }

        return new InventoryBatch(
            completeness: $partial ? BatchCompleteness::Partial : BatchCompleteness::Complete,
            observedAt: $context->now,
            sourceRef: 'mikrus:/serwery',
            items: $items,
            warnings: $warnings,
        );
    }

    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        // Unreachable through the orchestrator: the adapter declares no
        // cost capability, so this phase is never scheduled. Returned
        // only to satisfy the contract if a caller ignores that.
        return new CostFactBatch(
            completeness: BatchCompleteness::Unsupported,
            observedAt: $context->now,
            sourceRef: 'mikrus:cost-facts',
            facts: [],
            warnings: ['mikr.us exposes no billing API — no cost facts are ever produced.'],
        );
    }

    /**
     * The lifecycle-shaped fields of one `/info` body, passed through
     * raw under canonical keys — their types and formats are the
     * provider's own and are not interpreted here (documented shape
     * assumptions live in tests/Fixtures/Mikrus/README.md).
     *
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>|null
     */
    private function metadataOf(array $info): ?array
    {
        $metadata = [];

        foreach ([
            'expire' => 'expiration',
            'pro' => 'is_pro',
            'cytrus_expire' => 'cytrus_expiration',
            'storage_expire' => 'storage_expiration',
        ] as $from => $to) {
            if (array_key_exists($from, $info)) {
                $metadata[$to] = $info[$from];
            }
        }

        return $metadata === [] ? null : $metadata;
    }
}
