<?php

namespace App\Domain\Providers\Ovh;

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
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Read-only OVH adapter for inventory and contracted renewal quotes.
 *
 * OVH publishes two overlapping service APIs. `/services` (plural) is
 * the inventory surface: `GET /services` returns numeric ids, and
 * `GET /services/{id}` returns `services.expanded.Service` — resource
 * name, `route.path`, and `billing` (plan, current pricing, renew
 * mode/period, next billing date). `/service` (singular) is a separate
 * beta family whose useful read is `GET /service/{id}/renew`: a list of
 * *possible order combinations* (`RenewDescription[]`), not the
 * account's current charge.
 *
 * Quotes therefore start at `billing.pricing` on the expanded service
 * — one fact per inventoried id, never a bundled order preview. The
 * `/renew` payload is only a fallback when that pricing is missing, and
 * only the solo strategy for the configured period is accepted, so a
 * domain+hosting bundle cannot inflate or replace another service's
 * price. The public formatted catalog is a last-resort estimate.
 *
 * Public Cloud projects are never priced from a catalog or a renew
 * order: their real cost is usage, a separate unsupported capability.
 */
final class OvhProviderAdapter implements ProviderAdapter
{
    /**
     * `route.path` prefix => [canonical category, provider type].
     *
     * Prefixes are the published product APIs (`/1.0/` index + each
     * product schema), not invented segments. Matching is
     * `{prefix}` or `{prefix}/…`, so `/ip` cannot swallow
     * `/ipLoadbalancing`. A longer prefix must still precede any
     * prefix it extends (`/domain/zone` before `/domain`).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ROUTE_FAMILIES = [
        '/dedicated/server' => ['compute', 'dedicated_server'],
        '/dedicated/cluster' => ['compute', 'dedicated_cluster'],
        '/dedicated/housing' => ['compute', 'dedicated_housing'],
        '/dedicated/nasha' => ['storage', 'nasha'],
        '/dedicated/ceph' => ['storage', 'ceph'],
        '/dedicatedCloud' => ['compute', 'dedicated_cloud'],
        '/cloud/project' => ['compute', 'cloud_project'],
        '/hosting/privateDatabase' => ['database', 'private_database'],
        '/hosting/web' => ['other', 'web_hosting'],
        '/domain/zone' => ['dns', 'domain_zone'],
        '/domain' => ['domain', 'domain_name'],
        '/email/domain' => ['email', 'email_domain'],
        '/email/exchange' => ['email', 'email_exchange'],
        '/email/mxplan' => ['email', 'email_mxplan'],
        '/email/pro' => ['email', 'email_pro'],
        '/veeam/veeamEnterprise' => ['storage', 'veeam_enterprise'],
        '/veeamCloudConnect' => ['storage', 'veeam_cloud_connect'],
        '/ipLoadbalancing' => ['network', 'ip_loadbalancing'],
        '/sslGateway' => ['security', 'ssl_gateway'],
        '/cdn/dedicated' => ['network', 'cdn'],
        '/dbaas/logs' => ['observability', 'logs'],
        '/ovhCloudConnect' => ['network', 'cloud_connect'],
        '/license' => ['saas', 'license'],
        '/metrics' => ['observability', 'metrics'],
        '/nutanix' => ['compute', 'nutanix'],
        '/storage' => ['storage', 'storage'],
        '/vrack' => ['network', 'vrack'],
        '/ssl' => ['security', 'ssl'],
        '/vps' => ['compute', 'vps'],
        '/ip' => ['network', 'ip'],
    ];

    /**
     * OVH `priceInUcents` is micro-cents: 1 EUR = 100_000_000 ucents,
     * so one ISO minor unit (a cent) is 1_000_000 ucents.
     */
    private const UCENTS_PER_MINOR = 1_000_000;

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
        return new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::RenewalQuotes,
        ]);
    }

    public function fetchInventory(SyncContext $context): InventoryBatch
    {
        $api = $this->buildOvhApi->build($context->credentials);

        $listing = $api->get('/services');

        if (! is_array($listing)) {
            throw new TransientProviderException('OVH returned a malformed body for [/services].');
        }

        $items = [];
        $warnings = [];
        $malformed = 0;
        $failed = 0;

        foreach ($listing as $entry) {
            $serviceId = $this->listingId($entry);

            if ($serviceId === null) {
                $malformed++;

                continue;
            }

            try {
                $expanded = $api->get('/services/'.$serviceId);
            } catch (TransientProviderException) {
                $failed++;
                $warnings[] = "service [{$serviceId}] could not be loaded from [/services/{$serviceId}]; it is skipped.";

                continue;
            }

            if (! is_array($expanded)) {
                $malformed++;

                continue;
            }

            [$category, $providerType, $warning] = $this->classify($expanded, $serviceId);

            if ($warning !== null) {
                $warnings[] = $warning;
            }

            $items[] = new InventoryItem(
                externalId: $serviceId,
                category: $category,
                name: $this->resourceNameOf($expanded, $serviceId),
                providerType: $providerType,
                metadata: $this->metadataOf($expanded),
            );
        }

        if ($malformed > 0) {
            $warnings[] = "{$malformed} listing entries were malformed and are skipped; inventory is partial.";
        }

        $completeness = ($malformed === 0 && $failed === 0)
            ? BatchCompleteness::Complete
            : BatchCompleteness::Partial;

        return new InventoryBatch(
            completeness: $completeness,
            observedAt: $context->now,
            sourceRef: 'ovh:/services',
            items: $items,
            warnings: $warnings,
        );
    }

    /**
     * One renewal-estimate fact per inventoried service. The amount
     * comes from that service's `billing.pricing`; `/service/{id}/renew`
     * and the public catalog only run when that pricing is missing.
     */
    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $api = $this->buildOvhApi->build($context->credentials);
        $identity = $this->identity($api);

        $facts = [];
        /** @var list<string> $warnings */
        $warnings = $identity['warnings'];
        $catalogs = [];
        $degraded = false;

        foreach ($inventory->items as $item) {
            $meta = $this->meta($item);
            $period = $this->configuredPeriod($item);
            $isoPeriod = $meta['renewPeriod'] ?? null;

            if (is_string($isoPeriod) && $isoPeriod !== '' && $period === Period::Unknown) {
                $warnings[] = "renewal period [{$isoPeriod}] for [{$item->externalId}] is not a monthly/quarterly/annual cadence; period left unknown.";
            }

            $amount = null;
            $taxBasis = TaxBasis::Unknown;
            $notes = null;
            $allowFallback = ($meta['status'] ?? null) !== 'terminated';

            if (! $allowFallback) {
                $warnings[] = "service [{$item->externalId}] is terminated; renewal quote is skipped.";
            }

            try {
                $priced = $allowFallback ? $this->priceFromBilling($item) : null;

                if ($priced !== null) {
                    [$amount, $taxBasis] = $priced;
                    $notes = 'contracted price from the service billing plan.';
                    $allowFallback = false;
                }
            } catch (\InvalidArgumentException) {
                $degraded = true;
                $allowFallback = false;
                $warnings[] = "renewal price for [{$item->externalId}] could not be parsed; left unknown.";
            }

            if ($amount === null && $allowFallback && $item->providerType === 'cloud_project') {
                $warnings[] = "public cloud usage and billing are not yet synchronized; [{$item->externalId}] stays unknown.";
                $allowFallback = false;
            }

            if ($amount === null && $allowFallback && $item->providerType !== 'cloud_project') {
                $stopFallback = false;

                try {
                    $renewed = $this->priceFromRenew($api, $item, $period, $warnings, $stopFallback);

                    if ($stopFallback) {
                        $degraded = true;
                        $allowFallback = false;
                    }

                    if ($renewed !== null) {
                        [$amount, $taxBasis] = $renewed;
                        $notes = 'renewal quote from the solo /service/{id}/renew strategy.';
                        $allowFallback = false;
                    }
                } catch (TransientProviderException) {
                    $degraded = true;
                    $warnings[] = "renewal quote unavailable for [{$item->externalId}]; the strategy fetch failed.";
                } catch (\InvalidArgumentException) {
                    $degraded = true;
                    $allowFallback = false;
                    $warnings[] = "renewal price for [{$item->externalId}] could not be parsed; left unknown.";
                }
            }

            if ($amount === null && $allowFallback && $item->providerType !== 'cloud_project') {
                $family = $this->catalogFamily($item->providerType);

                if ($family !== null) {
                    $catalog = $catalogs[$family] ?? $this->loadCatalog($api, $family, $identity['subsidiary']);
                    $catalogs[$family] = $catalog;

                    if ($catalog === null) {
                        $degraded = true;
                        $warnings[] = "renewal pricing unavailable for [{$item->externalId}]; catalog [{$family}] failed.";
                    } else {
                        $fallback = $this->catalogPricing($catalog, $item, $period);

                        if ($fallback === null) {
                            $degraded = true;
                            $warnings[] = "no matching catalog plan for [{$item->externalId}]; price left unknown.";
                        } else {
                            try {
                                [$amount, $taxBasis] = $this->moneyFromOvh($fallback);
                                $notes = 'public catalog fallback; an estimate, not the account price.';
                            } catch (\InvalidArgumentException) {
                                $degraded = true;
                                $warnings[] = "renewal price for [{$item->externalId}] could not be parsed; left unknown.";
                            }
                        }
                    }
                }
            }

            if ($amount !== null
                && $identity['currency'] !== null
                && $amount->currency !== $identity['currency']
            ) {
                $warnings[] = "renewal price for [{$item->externalId}] is in {$amount->currency}, not the account currency {$identity['currency']}.";
            }

            $facts[] = new CostFact(
                sourceRef: 'ovh:service:'.$item->externalId,
                serviceExternalIds: [$item->externalId],
                sourceKind: SourceKind::RenewalQuote,
                chargeKind: ChargeKind::RecurringFixed,
                period: $period,
                evidenceState: EvidenceState::Estimate,
                amount: $amount,
                validFrom: $context->now->startOfDay(),
                taxBasis: $taxBasis,
                renewsAt: $this->dateOr($meta['nextBillingDate'] ?? null, $item->externalId, $warnings),
                autoRenew: ($meta['renewMode'] ?? null) === 'automatic',
                allocationState: AllocationState::Direct,
                notes: $notes,
            );
        }

        $completeness = $degraded ? BatchCompleteness::Partial : BatchCompleteness::Complete;

        return new CostFactBatch(
            completeness: $completeness,
            observedAt: $context->now,
            sourceRef: 'ovh:renewal-quotes',
            facts: $facts,
            warnings: $warnings,
            capabilityCompleteness: [
                ProviderCapability::RenewalQuotes->value => $completeness,
                ProviderCapability::Subscriptions->value => BatchCompleteness::Unsupported,
                ProviderCapability::Usage->value => BatchCompleteness::Unsupported,
                ProviderCapability::Invoices->value => BatchCompleteness::Unsupported,
            ],
            reportedCapabilities: [ProviderCapability::RenewalQuotes],
        );
    }

    /**
     * @return array{subsidiary: string|null, currency: string|null, warnings: list<string>}
     */
    private function identity(OvhApi $api): array
    {
        try {
            $me = $api->get('/me');
        } catch (TransientProviderException) {
            return ['subsidiary' => null, 'currency' => null, 'warnings' => ['account identity [/me] is unavailable; the catalog subsidiary and currency check are skipped.']];
        }

        if (! is_array($me)) {
            return ['subsidiary' => null, 'currency' => null, 'warnings' => ['account identity [/me] is unavailable; the catalog subsidiary and currency check are skipped.']];
        }

        $subsidiary = isset($me['ovhSubsidiary']) && is_string($me['ovhSubsidiary'])
            ? $me['ovhSubsidiary']
            : null;
        $currency = isset($me['currency']['currencyCode']) && is_string($me['currency']['currencyCode'])
            ? $me['currency']['currencyCode']
            : null;

        return ['subsidiary' => $subsidiary, 'currency' => $currency, 'warnings' => []];
    }

    /**
     * `GET /services` returns `long[]`. Anything else is a malformed
     * listing row, not an expanded service.
     */
    private function listingId(mixed $entry): ?string
    {
        if (is_int($entry) || (is_string($entry) && preg_match('/^\d+$/', $entry) === 1)) {
            return (string) $entry;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $expanded
     */
    private function resourceNameOf(array $expanded, string $serviceId): string
    {
        foreach (['displayName', 'name'] as $field) {
            $name = $expanded['resource'][$field] ?? null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $serviceId;
    }

    /**
     * Lifecycle fields the adapter owns on the canonical service, plus
     * the contracted pricing object cost facts read without a second
     * round-trip.
     *
     * @param  array<string, mixed>  $expanded
     * @return array<string, mixed>|null
     */
    private function metadataOf(array $expanded): ?array
    {
        $billing = is_array($expanded['billing'] ?? null) ? $expanded['billing'] : [];
        $plan = is_array($billing['plan'] ?? null) ? $billing['plan'] : [];
        $lifecycle = is_array($billing['lifecycle']['current'] ?? null) ? $billing['lifecycle']['current'] : [];
        $renew = is_array($billing['renew']['current'] ?? null) ? $billing['renew']['current'] : [];
        $engagement = is_array($billing['engagement'] ?? null) ? $billing['engagement'] : [];

        $metadata = [];

        if (is_string($plan['code'] ?? null) && $plan['code'] !== '') {
            $metadata['offer'] = $plan['code'];
        }

        if (is_string($plan['invoiceName'] ?? null) && $plan['invoiceName'] !== '') {
            $metadata['invoiceName'] = $plan['invoiceName'];
        }

        $status = $lifecycle['state'] ?? ($expanded['resource']['state'] ?? null);

        if (is_string($status) && $status !== '') {
            $metadata['status'] = $status;
        }

        foreach ([
            'creation' => $lifecycle['creationDate'] ?? null,
            'expiration' => $billing['expirationDate'] ?? null,
            'nextBillingDate' => $billing['nextBillingDate'] ?? ($renew['nextDate'] ?? null),
            'engagedUpTo' => $engagement['endDate'] ?? null,
        ] as $field => $value) {
            if (is_string($value) && $value !== '') {
                $metadata[$field] = $value;
            }
        }

        if (is_string($renew['mode'] ?? null) && $renew['mode'] !== '') {
            $metadata['renewMode'] = $renew['mode'];
        }

        if (is_string($renew['period'] ?? null) && $renew['period'] !== '') {
            $metadata['renewPeriod'] = $renew['period'];
        }

        $parent = $expanded['parentServiceId'] ?? null;

        if (is_int($parent) || (is_string($parent) && preg_match('/^\d+$/', $parent) === 1)) {
            $metadata['parentServiceId'] = (string) $parent;
        }

        $product = $expanded['resource']['product']['name'] ?? null;

        if (is_string($product) && $product !== '') {
            $metadata['productName'] = $product;
        }

        if (is_array($billing['pricing'] ?? null)) {
            $metadata['pricing'] = $billing['pricing'];
        }

        return $metadata === [] ? null : $metadata;
    }

    private function catalogFamily(?string $providerType): ?string
    {
        return match ($providerType) {
            'vps' => 'vps',
            'ip' => 'ip',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(InventoryItem $item): array
    {
        return is_array($item->metadata) ? $item->metadata : [];
    }

    private function configuredPeriod(InventoryItem $item): Period
    {
        $meta = $this->meta($item);
        $fromRenew = $this->renewalPeriod($meta['renewPeriod'] ?? null);

        if ($fromRenew !== Period::Unknown) {
            return $fromRenew;
        }

        $pricing = $meta['pricing'] ?? null;

        if (! is_array($pricing)) {
            return Period::Unknown;
        }

        return $this->pricingPeriod($pricing);
    }

    /**
     * @return array{0: Money, 1: TaxBasis}|null
     *
     * @throws \InvalidArgumentException
     */
    private function priceFromBilling(InventoryItem $item): ?array
    {
        $pricing = $this->meta($item)['pricing'] ?? null;

        if (! is_array($pricing)) {
            return null;
        }

        $type = $pricing['pricingType'] ?? null;

        if ($type === 'consumption') {
            return null;
        }

        $capacities = (array) ($pricing['capacities'] ?? []);

        if ($capacities !== [] && ! in_array('renew', $capacities, true) && in_array('consumption', $capacities, true)) {
            return null;
        }

        return $this->moneyFromOvh($pricing);
    }

    /**
     * Official `GET /service/{id}/renew` body: a list of
     * `{renewPeriod, strategies: [{services, price, priceInUcents}]}`.
     * Only the solo strategy for this service (and the configured
     * period, when one is known) is priced — bundled order previews
     * mix other services and are not a substitute for its charge.
     *
     * @param  list<string>  $warnings
     * @return array{0: Money, 1: TaxBasis}|null
     *
     * @throws TransientProviderException
     * @throws \InvalidArgumentException
     */
    private function priceFromRenew(OvhApi $api, InventoryItem $item, Period $period, array &$warnings, bool &$stopFallback): ?array
    {
        $renew = $api->get('/service/'.$item->externalId.'/renew');

        if (! is_array($renew)) {
            throw new TransientProviderException(
                "OVH returned a malformed body for [/service/{$item->externalId}/renew].",
            );
        }

        $wanted = $this->isoPeriod($period);
        $solo = null;
        $soloCount = 0;
        $sawBundled = false;

        foreach ($renew as $description) {
            if (! is_array($description)) {
                continue;
            }

            $renewPeriod = $description['renewPeriod'] ?? null;

            if ($wanted !== null && $renewPeriod !== $wanted && $renewPeriod !== $this->isoPeriodAlias($wanted)) {
                continue;
            }

            foreach ((array) ($description['strategies'] ?? []) as $strategy) {
                if (! is_array($strategy)) {
                    continue;
                }

                $covered = $this->strategyServiceIds($strategy);

                if ($covered === [$item->externalId]) {
                    $solo = $strategy;
                    $soloCount++;
                } elseif (in_array($item->externalId, $covered, true) && count($covered) > 1) {
                    $sawBundled = true;
                }
            }
        }

        if ($soloCount > 1) {
            $warnings[] = "renewal price for [{$item->externalId}] is ambiguous; left unknown.";
            $stopFallback = true;

            return null;
        }

        if ($solo === null) {
            if ($sawBundled) {
                $warnings[] = "renewal strategy for [{$item->externalId}] is a multi-service order preview and is not used as its contracted price.";
            }

            return null;
        }

        return $this->moneyFromOvh($solo);
    }

    /**
     * @param  array<string, mixed>  $strategy
     * @return list<string>
     */
    private function strategyServiceIds(array $strategy): array
    {
        $ids = [];

        foreach ((array) ($strategy['services'] ?? []) as $serviceId) {
            if (is_int($serviceId) || (is_string($serviceId) && preg_match('/^\d+$/', $serviceId) === 1)) {
                $ids[] = (string) $serviceId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCatalog(OvhApi $api, string $family, ?string $subsidiary): ?array
    {
        try {
            $parameters = $subsidiary !== null ? ['ovhSubsidiary' => $subsidiary] : [];
            $catalog = $api->get('/order/catalog/formatted/'.$family, $parameters);
        } catch (TransientProviderException) {
            return null;
        }

        return is_array($catalog) ? $catalog : null;
    }

    /**
     * Formatted vps/ip catalogs are `order.catalog.Catalog`: `plans[]`
     * with `planCode` and `details.pricings.default[]`.
     *
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>|null
     */
    private function catalogPricing(array $catalog, InventoryItem $item, Period $period): ?array
    {
        $offer = $this->meta($item)['offer'] ?? null;

        if (! is_string($offer) || $offer === '' || $period === Period::Unknown) {
            return null;
        }

        foreach ((array) ($catalog['plans'] ?? []) as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $code = $plan['planCode'] ?? null;
            $product = $plan['details']['product']['name'] ?? null;

            if ($code !== $offer && $product !== $offer) {
                continue;
            }

            foreach ((array) ($plan['details']['pricings']['default'] ?? []) as $pricing) {
                if (! is_array($pricing)) {
                    continue;
                }

                $capacities = (array) ($pricing['capacities'] ?? []);

                if ($capacities !== [] && ! in_array('renew', $capacities, true)) {
                    continue;
                }

                if ($this->pricingPeriod($pricing) === $period) {
                    return $pricing;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function pricingPeriod(array $pricing): Period
    {
        $fromDuration = $this->renewalPeriod($pricing['duration'] ?? null);

        if ($fromDuration !== Period::Unknown) {
            return $fromDuration;
        }

        $interval = $pricing['interval'] ?? null;
        $unit = $pricing['intervalUnit'] ?? null;

        if (! is_int($interval) || ! is_string($unit)) {
            return Period::Unknown;
        }

        return match ([$interval, $unit]) {
            [1, 'month'] => Period::Monthly,
            [3, 'month'] => Period::Quarterly,
            [12, 'month'], [1, 'year'] => Period::Annual,
            default => Period::Unknown,
        };
    }

    /**
     * Exact money from an OVH pricing/strategy object. `priceInUcents`
     * (micro-cents) is preferred; `price.value` is a JSON float and
     * only used when every ucents field is absent.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: Money, 1: TaxBasis}
     *
     * @throws \InvalidArgumentException
     */
    private function moneyFromOvh(array $payload): array
    {
        $currency = $payload['price']['currencyCode'] ?? null;

        if (! is_string($currency) || $currency === '') {
            throw new \InvalidArgumentException('missing currency');
        }

        $ucents = $payload['priceInUcents'] ?? ($payload['price']['priceInUcents'] ?? null);

        if (is_int($ucents)) {
            if ($ucents % self::UCENTS_PER_MINOR !== 0) {
                throw new \InvalidArgumentException('ucents are not aligned to minor units');
            }

            return [Money::ofMinor(intdiv($ucents, self::UCENTS_PER_MINOR), $currency), TaxBasis::Exclusive];
        }

        $value = $payload['price']['value'] ?? null;

        if (is_int($value)) {
            return [Money::ofMinor($value * 100, $currency), TaxBasis::Exclusive];
        }

        if (is_string($value)) {
            return [Money::ofString($value, $currency), TaxBasis::Exclusive];
        }

        if (is_float($value)) {
            return [Money::ofString(sprintf('%.2F', $value), $currency), TaxBasis::Exclusive];
        }

        throw new \InvalidArgumentException('missing price');
    }

    private function renewalPeriod(mixed $isoPeriod): Period
    {
        return match ($isoPeriod) {
            'P1M' => Period::Monthly,
            'P3M' => Period::Quarterly,
            'P12M', 'P1Y' => Period::Annual,
            default => Period::Unknown,
        };
    }

    private function isoPeriod(Period $period): ?string
    {
        return match ($period) {
            Period::Monthly => 'P1M',
            Period::Quarterly => 'P3M',
            Period::Annual => 'P1Y',
            default => null,
        };
    }

    private function isoPeriodAlias(string $isoPeriod): string
    {
        return match ($isoPeriod) {
            'P1Y' => 'P12M',
            'P12M' => 'P1Y',
            default => $isoPeriod,
        };
    }

    /**
     * @param  list<string>  $warnings
     */
    private function dateOr(mixed $value, string $serviceId, array &$warnings): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new CarbonImmutable($value);
        } catch (InvalidFormatException) {
            $warnings[] = "next billing date for [{$serviceId}] could not be parsed and is ignored.";

            return null;
        }
    }

    /**
     * Classify via `route.path` (and `route.url` when path is empty).
     * The route is an object on the expanded service, never a string.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function classify(array $entry, string $serviceId): array
    {
        $route = $entry['route'] ?? null;
        $path = is_array($route) ? ($route['path'] ?? $route['url'] ?? null) : $route;

        if (! is_string($path)) {
            return [
                'other',
                'unknown',
                'service ['.$serviceId.'] returned a non-string route ['.json_encode($route, JSON_UNESCAPED_SLASHES).']; classified as other.',
            ];
        }

        foreach (self::ROUTE_FAMILIES as $prefix => [$category, $providerType]) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return [$category, $providerType, null];
            }
        }

        $fallback = explode('/', trim($path, '/'))[0];

        return [
            'other',
            $fallback === '' ? 'unknown' : $fallback,
            "service [{$serviceId}] uses unmapped route [{$path}]; classified as other.",
        ];
    }
}
