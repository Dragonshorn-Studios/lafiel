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

/**
 * Read-only OVH adapter for inventory and renewal quotes. Discovery
 * starts at the common Services API (`GET /services`), whose entries
 * carry the numeric `serviceId` — the stable external id — next to the
 * technical service name and the `route` naming the product family.
 * The endpoint/version details stay inside this class; product-specific
 * resources are never used as identity.
 *
 * Renewal quotes come from `GET /service/{serviceId}/renew`: one fact
 * per renewal strategy, priced from the strategy's own selected prices.
 * A strategy covering several services is one fact linked to all of
 * them (`shared_unallocated`), never a copy of the price per service.
 * The public formatted catalog is only a fallback estimate for services
 * whose renewal strategy carries no usable price — never the customer's
 * committed contract price. The catalog subsidiary comes from the
 * connected account's `/me`, not a hard-coded country.
 *
 * Public Cloud projects are never priced from a catalog: their real
 * cost comes from resources and usage, which is a separate, currently
 * unsupported capability, so a project stays explicitly unknown.
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

    /**
     * Listing fields preserved verbatim as service metadata. The
     * catalog fallback needs `offer`; the rest is lifecycle context.
     *
     * @var list<string>
     */
    private const METADATA_FIELDS = ['status', 'offer', 'creation', 'expiration', 'engagedUpTo'];

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
        $entries = $api->get('/services');

        if (! is_array($entries)) {
            // A non-array body for a known path is a provider response
            // problem, not an inventory gap.
            throw new TransientProviderException('OVH returned a malformed body for [/services].');
        }

        $items = [];
        $warnings = [];
        $malformed = 0;

        foreach ($entries as $entry) {
            $serviceId = is_array($entry) ? $this->serviceIdOf($entry) : null;

            if ($serviceId === null) {
                $malformed++;

                continue;
            }

            [$category, $providerType, $warning] = $this->classify($entry, $serviceId);

            if ($warning !== null) {
                $warnings[] = $warning;
            }

            $items[] = new InventoryItem(
                externalId: $serviceId,
                category: $category,
                name: $this->serviceNameOf($entry, $serviceId),
                providerType: $providerType,
                metadata: $this->metadataOf($entry),
            );
        }

        if ($malformed > 0) {
            $warnings[] = "{$malformed} listing entries were malformed and are skipped; inventory is partial.";
        }

        return new InventoryBatch(
            completeness: $malformed === 0 ? BatchCompleteness::Complete : BatchCompleteness::Partial,
            observedAt: $context->now,
            sourceRef: 'ovh:/services',
            items: $items,
            warnings: $warnings,
        );
    }

    /**
     * One renewal-estimate fact per service renew strategy. The price
     * comes from the strategy's selected prices; only when the strategy
     * carries no usable price does the family's public catalog serve as
     * an explicit fallback estimate. A strategy that fails to fetch
     * degrades the capability to partial; an honest unknown price does
     * not — a Public Cloud project's unknown price is honest (its real
     * cost is usage, a separate unsupported capability), while a
     * missing catalog fallback match is a coverage gap and degrades.
     */
    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $api = $this->buildOvhApi->build($context->credentials);
        $identity = $this->identity($api);

        $facts = [];
        $warnings = $identity['warnings'];
        $catalogs = [];
        $degraded = false;

        $inventoryIds = collect($inventory->items)
            ->map(fn (InventoryItem $item): string => $item->externalId)
            ->all();

        // Sibling services can carry identical copies of one strategy
        // payload; the covered-set reference dedupes them to one fact.
        $emittedRefs = [];

        foreach ($inventory->items as $item) {
            try {
                $renew = $api->get('/service/'.$item->externalId.'/renew');

                if (! is_array($renew)) {
                    // A non-array body for a known path is a provider
                    // response problem, not an inventory gap.
                    throw new TransientProviderException(
                        "OVH returned a malformed body for [/service/{$item->externalId}/renew].",
                    );
                }
            } catch (TransientProviderException) {
                $degraded = true;
                $warnings[] = "renewal quote unavailable for [{$item->externalId}]; the strategy fetch failed.";

                continue;
            }

            [$coveredIds, $selectedLabels, $period, $autoRenew, $usable] = $this->strategy($renew, $item, $inventoryIds, $warnings);

            $sourceRef = 'ovh:renew:'.implode('+', $coveredIds);

            if (isset($emittedRefs[$sourceRef])) {
                continue;
            }

            $emittedRefs[$sourceRef] = true;

            $amount = null;
            $taxBasis = TaxBasis::Unknown;
            $notes = null;

            try {
                $parts = null;
                $ambiguous = false;

                if ($usable) {
                    [$parts, $ambiguous] = $this->selectedPrice($renew, $selectedLabels, $item->externalId, $warnings);
                }

                if ($parts !== null) {
                    if ($this->mixesPeriods($parts, $period)) {
                        // Prices from different duration buckets must
                        // never be summed under one period.
                        $degraded = true;
                        $warnings[] = "renewal price for [{$item->externalId}] mixes renewal periods; left unknown.";
                    } else {
                        [$amount, $taxBasis] = $this->priceFromParts($parts);
                        $notes = 'renewal quote from the service renewal strategy.';
                    }
                } elseif ($ambiguous) {
                    $degraded = true;
                    $warnings[] = "renewal price for [{$item->externalId}] is ambiguous; left unknown.";
                }
            } catch (\InvalidArgumentException) {
                // A price we cannot express exactly stays unknown; the
                // run degrades so the gap is visible instead of a crash
                // ending it.
                $degraded = true;
                $warnings[] = "renewal price for [{$item->externalId}] could not be parsed; left unknown.";
            }

            // Public Cloud has no renewal-priced plan at all: its real
            // cost comes from resources and usage, which is an explicit
            // Usage/Invoices gap (unsupported), never a catalog price.
            // The unknown price here is honest, not a coverage gap.
            if ($amount === null && $item->providerType === 'cloud_project') {
                $warnings[] = "public cloud usage and billing are not yet synchronized; [{$item->externalId}] stays unknown.";
            }

            if ($amount === null && $item->providerType !== 'cloud_project') {
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
                                [$amount, $taxBasis] = $this->priceFromPricing($fallback);
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
                sourceRef: $sourceRef,
                serviceExternalIds: $coveredIds,
                sourceKind: SourceKind::RenewalQuote,
                chargeKind: ChargeKind::RecurringFixed,
                period: $period,
                evidenceState: EvidenceState::Estimate,
                amount: $amount,
                validFrom: $context->now->startOfDay(),
                taxBasis: $taxBasis,
                autoRenew: $autoRenew,
                allocationState: count($coveredIds) > 1 ? AllocationState::SharedUnallocated : AllocationState::Direct,
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
     * The account identity the adapter must respect: the OVH subsidiary
     * that selects public catalogs and the account's billing currency.
     * Identity failures are warnings, not phase failures — the catalog
     * merely falls back to no subsidiary and no currency check.
     *
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
     * The numeric serviceId of one listing entry — the stable external
     * identity — or null when the entry is malformed.
     */
    private function serviceIdOf(mixed $entry): ?string
    {
        $serviceId = $entry['serviceId'] ?? null;

        if (is_int($serviceId) || (is_string($serviceId) && preg_match('/^\d+$/', $serviceId) === 1)) {
            return (string) $serviceId;
        }

        return null;
    }

    /**
     * The technical service name, falling back to the service id —
     * a listing entry without a name is odd but still inventoried.
     */
    private function serviceNameOf(array $entry, string $serviceId): string
    {
        $name = $entry['serviceName'] ?? null;

        return is_string($name) && $name !== '' ? $name : $serviceId;
    }

    /**
     * @return array<string, mixed>|null the preserved listing fields
     */
    private function metadataOf(array $entry): ?array
    {
        $metadata = [];

        foreach (self::METADATA_FIELDS as $field) {
            if (isset($entry[$field]) && is_scalar($entry[$field])) {
                $metadata[$field] = $entry[$field];
            }
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * The formatted catalog that prices one provider type as a
     * fallback. A type with no catalog simply stays unpriced.
     */
    private function catalogFamily(?string $providerType): ?string
    {
        return match ($providerType) {
            'vps' => 'vps',
            'domain_zone', 'domain_name' => 'domain',
            'ip' => 'ip',
            default => null,
        };
    }

    /**
     * The renewal strategy for one inventory item: the covered
     * inventory ids, the selected price labels, the renewal period,
     * and the auto-renew flag. Every inventoried service gets a
     * strategy — an odd or empty payload yields a single-service
     * unknown fallback — so a service still in inventory always has a
     * reported charge and its absence can never read as cancellation.
     *
     * @param  list<string>  $inventoryIds
     * @return array{0: list<string>, 1: list<string>, 2: Period, 3: bool, 4: bool}
     *                                                                              the last flag marks whether the payload described a
     *                                                                              strategy this run can price at all
     */
    private function strategy(array $renew, InventoryItem $item, array $inventoryIds, array &$warnings): array
    {
        $covered = [];
        $labels = [];
        $listed = 0;

        foreach ((array) ($renew['services'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $serviceId = $this->serviceIdOf($entry);

            if ($serviceId === null) {
                continue;
            }

            $listed++;

            if (in_array($serviceId, $inventoryIds, true)) {
                $covered[] = $serviceId;
            } else {
                $warnings[] = "renewal strategy for [{$item->externalId}] covers [{$serviceId}], which is outside this run's inventory; it is not linked.";
            }

            if (is_string($entry['selectedPrice'] ?? null) && $entry['selectedPrice'] !== '') {
                $labels[] = $entry['selectedPrice'];
            }
        }

        foreach ((array) ($renew['options'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['selectedPrice'] ?? null) && $entry['selectedPrice'] !== '') {
                $labels[] = $entry['selectedPrice'];
            }
        }

        $covered = array_values(array_unique($covered));
        sort($covered, SORT_NUMERIC);

        // An empty strategy payload quotes nothing; a payload that
        // lists services but none of this run's is a gap worth
        // surfacing. Both keep the service's own unknown quote alive,
        // so a service still in inventory always has a reported charge
        // and its absence can never read as cancellation.
        if ($covered === [] || ! in_array($item->externalId, $covered, true)) {
            if ($listed > 0) {
                $warnings[] = "renewal strategy for [{$item->externalId}] covers none of this run's services; price left unknown.";
            }

            return [[$item->externalId], [], Period::Unknown, false, false];
        }

        $labels = array_values(array_unique($labels));

        return [
            $covered,
            $labels,
            $this->renewalPeriod($this->configuredPeriod($renew, $covered)),
            $this->autoRenewOf($renew, $covered),
            true,
        ];
    }

    /**
     * The renewal period the account configured, taken from the first
     * covered strategy service that expresses one — entries pointing
     * outside this run's inventory never speak for the fact.
     */
    private function configuredPeriod(array $renew, array $covered): mixed
    {
        foreach ((array) ($renew['services'] ?? []) as $entry) {
            if (! is_array($entry) || ! in_array((string) ($entry['serviceId'] ?? ''), $covered, true)) {
                continue;
            }

            $period = $entry['renew']['period'] ?? null;

            if (is_string($period) && $period !== '') {
                return $period;
            }
        }

        return null;
    }

    private function autoRenewOf(array $renew, array $covered): bool
    {
        foreach ((array) ($renew['services'] ?? []) as $entry) {
            if (! is_array($entry) || ! in_array((string) ($entry['serviceId'] ?? ''), $covered, true)) {
                continue;
            }

            if (($entry['renew']['automatic'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The price parts backing the strategy's selections: every selected
     * label's price, or — when the payload selects nothing but carries
     * exactly one price — that price. A payload with several prices and
     * no selection is ambiguous; the flag is reported and the price
     * stays unknown rather than guessed. A selected label with no price
     * part is a provider payload gap and is warned about.
     *
     * @param  list<string>  $selectedLabels
     * @return array{0: list<array<string, mixed>>|null, 1: bool} the
     *                                                            price parts (null when unusable) and the ambiguity flag
     */
    private function selectedPrice(array $renew, array $selectedLabels, string $serviceId, array &$warnings): array
    {
        $prices = [];

        foreach ((array) ($renew['prices'] ?? []) as $price) {
            if (is_array($price) && is_string($price['label'] ?? null)) {
                $prices[$price['label']] = $price;
            }
        }

        if ($selectedLabels === []) {
            if (count($prices) === 1) {
                return [[reset($prices)], false];
            }

            return [null, count($prices) > 1];
        }

        $parts = [];

        foreach ($selectedLabels as $label) {
            if (! isset($prices[$label])) {
                $warnings[] = "renewal price [{$label}] selected for [{$serviceId}] has no price part; price left unknown.";

                return [null, false];
            }

            $parts[] = $prices[$label];
        }

        return [$parts, false];
    }

    /**
     * Whether the selected parts span duration buckets the fact's
     * single renewal period cannot express. Prices without a duration
     * and an unresolvable period are not judged.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    private function mixesPeriods(array $parts, Period $period): bool
    {
        $allowed = match ($period) {
            Period::Monthly => ['P1M'],
            Period::Quarterly => ['P3M'],
            Period::Annual => ['P12M', 'P1Y'],
            default => null,
        };

        if ($allowed === null) {
            return false;
        }

        foreach ($parts as $part) {
            $duration = $part['duration'] ?? null;

            if (is_string($duration) && ! in_array($duration, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The selected price parts summed into one exact amount. The parts
     * must share one currency: `priceInUtv` is the exact integer
     * minor-unit price and is preferred wherever every part carries
     * it. The decimal `price.value` is a JSON float and only ever
     * reaches Money through PHP's float rendering, so it is the
     * fallback, and anything Money cannot express exactly (mixed
     * currencies, more than two decimals, E-notation) throws to the
     * caller's unknown-price path.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return array{0: Money, 1: TaxBasis}
     *
     * @throws \InvalidArgumentException
     */
    private function priceFromParts(array $parts): array
    {
        $currencies = [];

        foreach ($parts as $part) {
            $currencies[] = (string) ($part['price']['currencyCode'] ?? '');
        }

        if (count(array_unique($currencies)) > 1) {
            throw new \InvalidArgumentException('strategy parts mix currencies');
        }

        $taxBasis = TaxBasis::Exclusive;

        foreach ($parts as $part) {
            if (($part['tax']['mode'] ?? null) !== 'vat-excluded') {
                $taxBasis = TaxBasis::Unknown;
            }
        }

        $minor = 0;

        foreach ($parts as $part) {
            if (! is_int($part['priceInUtv'] ?? null)) {
                return $this->priceFromDecimalParts($parts, $taxBasis);
            }

            $minor += $part['priceInUtv'];
        }

        $currency = (string) ($parts[0]['price']['currencyCode'] ?? '');

        return [Money::ofMinor($minor, $currency), $taxBasis];
    }

    /**
     * @return array{0: Money, 1: TaxBasis}
     *
     * @throws \InvalidArgumentException
     */
    private function priceFromDecimalParts(array $parts, TaxBasis $taxBasis): array
    {
        $total = null;

        foreach ($parts as $part) {
            $amount = Money::ofString((string) ($part['price']['value'] ?? ''), (string) ($part['price']['currencyCode'] ?? ''));
            $total = $total === null ? $amount : $total->add($amount);
        }

        return [$total, $taxBasis];
    }

    /**
     * The formatted catalog that prices one provider type's fallback.
     * A fetch that fails returns null; the caller degrades the run
     * instead of crashing.
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
     * The pricing part matching the service's offer and renewal
     * period. The offer comes from the preserved listing metadata —
     * a service without one has nothing to match on.
     *
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>|null
     */
    private function catalogPricing(array $catalog, InventoryItem $item, Period $period): ?array
    {
        $offer = $item->metadata['offer'] ?? null;
        $duration = match ($period) {
            Period::Monthly => 'P1M',
            Period::Quarterly => 'P3M',
            Period::Annual => 'P12M',
            default => null,
        };

        if (! is_string($offer) || $offer === '' || $duration === null) {
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
     * The catalog pricing part converted to an exact amount. `priceInUtv`
     * is the catalog's own integer minor-unit price — exact by
     * construction. The decimal `price.value` is a JSON float and only
     * ever reaches Money through PHP's float rendering, so it is the
     * fallback, and anything Money cannot express exactly (more than
     * two decimals, E-notation) throws to the caller's unknown-price
     * path.
     *
     * @param  array<string, mixed>  $pricing
     * @return array{0: Money, 1: TaxBasis}
     *
     * @throws \InvalidArgumentException
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
     * Map one listing entry's route onto the canonical category and the
     * raw provider type. An unmapped route stays visible: it is
     * classified as `other` and reported as a warning rather than
     * dropped.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function classify(array $entry, string $serviceId): array
    {
        $route = $entry['route'] ?? '';

        // The API does not guarantee the documented string shape for
        // every service, so a non-scalar route is classed as other —
        // never cast, which would crash the whole run.
        if (! is_string($route)) {
            return [
                'other',
                'unknown',
                'service ['.$serviceId.'] returned a non-string route ['.json_encode($route, JSON_UNESCAPED_SLASHES).']; classified as other.',
            ];
        }

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
            "service [{$serviceId}] uses unmapped route [{$route}]; classified as other.",
        ];
    }
}
