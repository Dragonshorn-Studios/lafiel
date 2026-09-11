<?php

namespace App\Domain\Providers\Ovh;

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
use App\Domain\Providers\Exceptions\TransientProviderException;

/**
 * Read-only OVH inventory adapter. Discovery starts at the common
 * Service API (`GET /service`), then one metadata call per service
 * (`GET /service/{name}`) whose `route` names the product family — the
 * endpoint/version details stay inside this class. The service name is
 * the stable external id; the route family is kept as provider type
 * next to the canonical category.
 */
final class OvhProviderAdapter implements ProviderAdapter
{
    /**
     * Longest route prefixes first, so a nested family is never shadowed
     * by its parent segment.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ROUTE_FAMILIES = [
        '/dedicated/server' => ['compute', 'dedicated_server'],
        '/cloud/project' => ['compute', 'cloud_project'],
        '/domain/zone' => ['dns', 'domain_zone'],
        '/domain/name' => ['domain', 'domain_name'],
        '/email/domain' => ['email', 'email_domain'],
        '/email/pro' => ['email', 'email_pro'],
        '/hosting/privateDatabase' => ['database', 'private_database'],
        '/vps' => ['compute', 'vps'],
        '/ip' => ['network', 'ip'],
    ];

    public function __construct(private readonly BuildOvhApi $buildOvhApi) {}

    public function validateCredentials(SyncContext $context): CredentialCheck
    {
        try {
            $this->buildOvhApi->build($context->credentials)->get('/me');
        } catch (InvalidCredentialsException $exception) {
            return CredentialCheck::invalid($exception->getMessage());
        }

        return CredentialCheck::valid();
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet([ProviderCapability::Inventory]);
    }

    public function fetchInventory(SyncContext $context): InventoryBatch
    {
        $api = $this->buildOvhApi->build($context->credentials);

        // Without the listing there is no usable inventory this run, so
        // a listing failure fails the whole phase and the orchestrator
        // keeps the last good data.
        $names = $api->get('/service');

        $items = [];
        $warnings = [];
        $unavailable = 0;

        foreach ((array) $names as $name) {
            $name = (string) $name;

            try {
                $service = $api->get('/service/'.$name);
            } catch (TransientProviderException) {
                $unavailable++;

                continue;
            }

            [$category, $providerType, $warning] = $this->classify($service, $name);

            if ($warning !== null) {
                $warnings[] = $warning;
            }

            $items[] = new InventoryItem(
                externalId: $name,
                category: $category,
                name: $name,
                providerType: $providerType,
            );
        }

        if ($unavailable > 0) {
            $warnings[] = "service metadata unavailable for {$unavailable} services; inventory is partial.";
        }

        return new InventoryBatch(
            completeness: $unavailable === 0 ? BatchCompleteness::Complete : BatchCompleteness::Partial,
            observedAt: $context->now,
            sourceRef: 'ovh:/service',
            items: $items,
            warnings: $warnings,
        );
    }

    /**
     * The capability set declares no cost capabilities, so the
     * orchestrator never calls this; reaching here is a programming
     * error, not an empty observation.
     */
    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        throw new \LogicException('The OVH adapter declares no cost capabilities; fetchCostFacts must never be called.');
    }

    /**
     * Map one service's route onto the canonical category and the raw
     * provider type. An unmapped route stays visible: it is classified
     * as `other` and reported as a warning rather than dropped.
     *
     * @param  array<string, mixed>  $service
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function classify(array $service, string $name): array
    {
        $route = (string) ($service['route'] ?? '');

        foreach (self::ROUTE_FAMILIES as $prefix => [$category, $providerType]) {
            if (str_starts_with($route, $prefix)) {
                return [$category, $providerType, null];
            }
        }

        $fallback = explode('/', trim($route, '/'))[0] ?? '';

        return [
            'other',
            $fallback === '' ? 'unknown' : $fallback,
            "service [{$name}] uses unmapped route [{$route}]; classified as other.",
        ];
    }
}
