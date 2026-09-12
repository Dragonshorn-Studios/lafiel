<?php

namespace App\Domain\Providers\Ovh;

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

/**
 * Read-only OVH adapter for inventory and renewal quotes. Discovery
 * starts at the common Service API (`GET /service`), then one metadata
 * call per service (`GET /service/{name}`) whose `route` names the
 * product family — the endpoint/version details stay inside this
 * class. The service name is the stable external id; the route family
 * is kept as provider type next to the canonical category.
 *
 * Renewal pricing is read from the family's formatted catalog and
 * modeled as an estimate — catalog output is never an actual, and a
 * missing plan leaves the price unknown rather than inferred. Every
 * v1 renewal price belongs to exactly one service, so allocation is
 * `direct`; a future price part covering several services without a
 * per-service attribution would set `shared_unallocated` instead.
 */
final class OvhProviderAdapter implements ProviderAdapter
{
    /**
     * Route prefix => [canonical category, provider type]. When adding
     * entries, a longer prefix must precede any prefix it extends.
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
        return new CapabilitySet([
            ProviderCapability::Inventory,
            ProviderCapability::RenewalQuotes,
        ]);
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

                if (! is_array($service)) {
                    // A non-array body for a known path is a provider
                    // response problem, not an inventory gap.
                    throw new TransientProviderException("OVH returned a malformed body for [/service/{$name}].");
                }
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
     * One renewal-estimate fact per inventoried service. The renewal
     * period comes from the service's renew strategy, the price from
     * the family's formatted catalog — modeled as an estimate, with a
     * missing plan leaving the price unknown, never inferred. A fetch
     * that fails degrades the capability to partial; an honest unknown
     * price does not.
     */
    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $api = $this->buildOvhApi->build($context->credentials);

        $facts = [];
        $warnings = [];
        $catalogs = [];
        $failedFamilies = [];
        $degraded = false;

        foreach ($inventory->items as $item) {
            $name = $item->externalId;

            try {
                $service = $api->get('/service/'.$name);

                if (! is_array($service)) {
                    // A non-array body for a known path is a provider
                    // response problem, not an inventory gap.
                    throw new TransientProviderException("OVH returned a malformed body for [/service/{$name}].");
                }
            } catch (TransientProviderException) {
                $degraded = true;
                $warnings[] = "renewal facts unavailable for [{$name}]; service metadata failed.";

                continue;
            }

            $renew = is_array($service['renew'] ?? null) ? $service['renew'] : [];
            $period = $this->renewalPeriod($renew['period'] ?? null);
            $taxBasis = TaxBasis::Unknown;
            $amount = null;

            $family = $this->catalogFamily($item->providerType);

            if ($family !== null) {
                $catalog = $catalogs[$family] ?? $this->loadCatalog($api, $family);
                $catalogs[$family] = $catalog;

                if ($catalog === null) {
                    $degraded = true;
                    $failedFamilies[$family] = ($failedFamilies[$family] ?? 0) + 1;
                } else {
                    $pricing = $this->catalogPricing($catalog, $service, $period);

                    if ($pricing !== null) {
                        try {
                            [$amount, $taxBasis] = $this->priceFromPricing($pricing);
                        } catch (\InvalidArgumentException) {
                            // A price we cannot express exactly stays
                            // unknown; the run degrades so the gap is
                            // visible instead of a crash ending it.
                            $degraded = true;
                            $warnings[] = "renewal price for [{$name}] could not be parsed; left unknown.";
                        }
                    }
                }
            }

            $facts[] = new CostFact(
                sourceRef: 'ovh:renewal:'.$name,
                serviceExternalIds: [$name],
                sourceKind: SourceKind::RenewalQuote,
                chargeKind: ChargeKind::RecurringFixed,
                period: $period,
                evidenceState: EvidenceState::Estimate,
                amount: $amount,
                validFrom: $context->now->startOfDay(),
                taxBasis: $taxBasis,
                renewsAt: $this->renewalDate($renew, $name, $warnings),
                autoRenew: (bool) ($renew['automatic'] ?? false),
            );
        }

        // One warning per failed family with the affected service
        // count, instead of one near-identical warning per service.
        foreach ($failedFamilies as $family => $count) {
            $warnings[] = "renewal pricing unavailable for {$count} services; catalog [{$family}] failed.";
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
     * @return array<string, mixed>|null the catalog payload, or null when
     *                                   it could not be fetched this run
     */
    private function loadCatalog(OvhApi $api, string $family): ?array
    {
        try {
            $catalog = $api->get('/order/catalog/formatted/'.$family, ['ovhSubsidiary' => 'IE']);
        } catch (TransientProviderException) {
            return null;
        }

        return is_array($catalog) ? $catalog : null;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>|null the pricing part matching the
     *                                   service offer and renewal period
     */
    private function catalogPricing(array $catalog, array $service, Period $period): ?array
    {
        $offer = (string) ($service['offer'] ?? '');
        $duration = match ($period) {
            Period::Monthly => 'P1M',
            Period::Quarterly => 'P3M',
            Period::Annual => 'P12M',
            default => null,
        };

        if ($offer === '' || $duration === null) {
            return null;
        }

        foreach ($catalog['catalog'] ?? [] as $entry) {
            foreach ($entry['products'] ?? [] as $product) {
                if (($product['name'] ?? null) !== $offer) {
                    continue;
                }

                foreach ($product['pricings'] ?? [] as $pricing) {
                    if (($pricing['duration'] ?? null) === $duration
                        && isset($pricing['price']['value'], $pricing['price']['currencyCode'])) {
                        return $pricing;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The pricing part converted to an exact amount. `priceInUtv` is
     * the catalog's own integer minor-unit price — exact by
     * construction. The decimal `price.value` is a JSON float and only
     * ever reaches Money through PHP's float rendering, so it is the
     * fallback, and anything Money cannot express exactly (more than
     * two decimals, E-notation) throws to the caller's unknown-price
     * path.
     *
     * @param  array<string, mixed>  $pricing
     * @return array{0: Money, 1: TaxBasis}
     */
    private function priceFromPricing(array $pricing): array
    {
        $taxBasis = ($pricing['tax']['mode'] ?? null) === 'vat-excluded'
            ? TaxBasis::Exclusive
            : TaxBasis::Unknown;

        if (is_int($pricing['priceInUtv'] ?? null)) {
            return [Money::ofMinor($pricing['priceInUtv'], (string) $pricing['price']['currencyCode']), $taxBasis];
        }

        return [
            Money::ofString((string) $pricing['price']['value'], (string) $pricing['price']['currencyCode']),
            $taxBasis,
        ];
    }

    /**
     * A renewal date the source cannot express cleanly is ignored with
     * a warning naming the service — never a crashed run.
     *
     * @param  array<string, mixed>  $renew
     * @param  list<string>  $warnings
     */
    private function renewalDate(array $renew, string $name, array &$warnings): ?CarbonImmutable
    {
        $deleteAt = $renew['deleteAt'] ?? null;

        if (! is_string($deleteAt)) {
            return null;
        }

        try {
            return new CarbonImmutable($deleteAt);
        } catch (\InvalidArgumentException) {
            $warnings[] = "renewal date for [{$name}] could not be parsed; ignored.";

            return null;
        }
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

    /**
     * The formatted catalog that prices one provider type. A type with
     * no catalog simply stays unpriced this run — that is v1 scope,
     * not a degraded fetch.
     */
    private function catalogFamily(?string $providerType): ?string
    {
        return match ($providerType) {
            'vps' => 'vps',
            'cloud_project' => 'cloud',
            'domain_zone', 'domain_name' => 'domain',
            'ip' => 'ip',
            default => null,
        };
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

        // explode always yields at least one segment; an empty route
        // yields an empty one, which maps to the unknown type below.
        $fallback = explode('/', trim($route, '/'))[0];

        return [
            'other',
            $fallback === '' ? 'unknown' : $fallback,
            "service [{$name}] uses unmapped route [{$route}]; classified as other.",
        ];
    }
}
