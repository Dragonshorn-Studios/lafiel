<?php

namespace App\Domain\Providers\Hetzner\Cloud;

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
use App\Domain\Support\ValueObjects\Money;
use App\Domain\Support\ValueObjects\Rational;

/**
 * Read-only Hetzner Cloud adapter for inventory and catalog-priced
 * recurring charges. Servers, load balancers, primary IPs, floating
 * IPs, and volumes are discovered by their stable numeric ids; each
 * resource is joined to `GET /pricing` by type and location.
 *
 * The catalog output is an estimate — currency and VAT rate come from
 * the pricing endpoint, amounts are net — never an invoice actual, so
 * a current catalog price does not become the actual of an old server.
 * A later catalog change closes the old fact version and opens a new
 * one; history is never rewritten. A resource without a matching
 * price entry is honestly unknown.
 */
final class HetznerCloudProviderAdapter implements ProviderAdapter
{
    private const PER_PAGE = 50;

    /** A misbehaving page total must not spin the walk forever. */
    private const MAX_PAGES = 100;

    /**
     * Resource sections: collection path => [collection key, canonical
     * category, pricing join table, primary flag]. The primary section
     * is the one whose failure fails the whole phase.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    private const SECTIONS = [
        '/servers' => ['servers', 'compute', 'server_types', true],
        '/load_balancers' => ['load_balancers', 'network', 'load_balancer_types', false],
        '/primary_ips' => ['primary_ips', 'network', 'primary_ips', false],
        '/floating_ips' => ['floating_ips', 'network', 'floating_ips', false],
        '/volumes' => ['volumes', 'storage', 'volumes', false],
    ];

    /**
     * Pricing joins captured during the inventory fetch (same run,
     * same adapter instance): external id => [pricing table, type,
     * location, volume size in GB or null]. The adapter is a container
     * singleton, so the map is reset at the start of every inventory
     * fetch — without that, entries from prior runs and other accounts
     * would accumulate for the life of a queue worker.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: ?int}>
     */
    private array $priceJoins = [];

    public function __construct(private readonly BuildHetznerCloudApi $buildApi) {}

    public function validateCredentials(SyncContext $context): CredentialCheck
    {
        try {
            $this->buildApi->build($context->credentials)->get('/servers', ['per_page' => 1]);
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
        // Cost facts are built from these joins; they describe this
        // observation only. Fetching inventory is what starts a run's
        // join state — a fetchCostFacts without a same-instance
        // fetchInventory before it finds nothing to price from.
        $this->priceJoins = [];

        $api = $this->buildApi->build($context->credentials);
        $warnings = [];
        $items = [];
        $partial = false;

        foreach (self::SECTIONS as $path => [$collectionKey, $category, $joinTable, $primary]) {
            try {
                [$resources, $truncated] = $this->collect($api, $path, $collectionKey, $warnings);
                $partial = $partial || $truncated;
            } catch (InvalidCredentialsException $exception) {
                throw $exception;
            } catch (ProviderException $exception) {
                // The primary listing failing fails the whole phase —
                // the exception propagates and the run keeps its last
                // good data. Secondary sections degrade the batch.
                if ($primary) {
                    throw $exception;
                }

                $partial = true;
                $warnings[] = sprintf('listing [%s] failed: %s', $path, $exception->getMessage());

                continue;
            }

            foreach ($resources as $resource) {
                $item = $this->itemFor($resource, $category, $joinTable);

                if ($item === null) {
                    $partial = true;
                    $warnings[] = sprintf('a resource in [%s] without an id, name, or location was skipped.', $path);

                    continue;
                }

                $items[] = $item;
            }
        }

        return new InventoryBatch(
            completeness: $partial ? BatchCompleteness::Partial : BatchCompleteness::Complete,
            observedAt: $context->now,
            sourceRef: 'hetzner-cloud:/servers,/load_balancers,/primary_ips,/floating_ips,/volumes',
            items: $items,
            warnings: $warnings,
        );
    }

    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $api = $this->buildApi->build($context->credentials);
        $warnings = [];
        $unpriced = 0;

        try {
            $pricing = $api->get('/pricing');
        } catch (InvalidCredentialsException $exception) {
            throw $exception;
        } catch (ProviderException $exception) {
            $pricing = null;
            $warnings[] = 'pricing join failed: '.$exception->getMessage();
        }

        $currency = $this->currencyOf($pricing);
        $vatRate = is_string($pricing['vat_rate'] ?? null) ? $pricing['vat_rate'] : '';
        $notes = 'Hetzner Cloud catalog (net of VAT'.($vatRate !== '' ? ' '.$vatRate : '').')';

        $facts = [];

        foreach ($inventory->items as $item) {
            $join = $this->priceJoins[$item->externalId] ?? null;
            $minor = $join === null ? null : $this->catalogMinor($pricing, $join, $warnings);

            if ($minor !== null && $currency !== null) {
                $facts[] = new CostFact(
                    sourceRef: sprintf('hetzner:cloud:resource:%s', $item->externalId),
                    serviceExternalIds: [$item->externalId],
                    sourceKind: SourceKind::Subscription,
                    chargeKind: ChargeKind::RecurringFixed,
                    period: Period::Monthly,
                    evidenceState: EvidenceState::Estimate,
                    amount: Money::ofMinor($minor, $currency),
                    validFrom: $context->now->startOfDay(),
                    taxBasis: TaxBasis::Exclusive,
                    allocationState: AllocationState::Direct,
                    notes: $notes,
                );

                continue;
            }

            // No matching catalog entry (or no usable currency): the
            // recurring charge still exists — its price stays unknown,
            // and the fact records that honestly.
            $unpriced++;
            $facts[] = new CostFact(
                sourceRef: sprintf('hetzner:cloud:resource:%s', $item->externalId),
                serviceExternalIds: [$item->externalId],
                sourceKind: SourceKind::Subscription,
                chargeKind: ChargeKind::RecurringFixed,
                period: Period::Monthly,
                evidenceState: EvidenceState::Estimate,
                amount: null,
                validFrom: $context->now->startOfDay(),
                taxBasis: TaxBasis::Unknown,
                allocationState: AllocationState::Direct,
            );
        }

        if ($unpriced > 0) {
            $warnings[] = sprintf('%d resource(s) have no catalog price; their charges stay unknown.', $unpriced);
        }

        // Pricing failing degrades the subscription observation without
        // invalidating it: what was observed is still observed.
        $completeness = $pricing === null ? BatchCompleteness::Partial : BatchCompleteness::Complete;

        return new CostFactBatch(
            completeness: $completeness,
            observedAt: $context->now,
            sourceRef: 'hetzner-cloud:catalog-subscriptions',
            facts: $facts,
            warnings: $warnings,
            capabilityCompleteness: [
                ProviderCapability::Subscriptions->value => $completeness,
            ],
            reportedCapabilities: [ProviderCapability::Subscriptions],
        );
    }

    /**
     * One discovered resource in, one inventory item plus its pricing
     * join out. A pricing entry is matched by type and location;
     * primary IPs and floating IPs may carry no name — the IP itself
     * is the display name, never an invented one.
     *
     * @param  array<string, mixed>  $resource
     */
    private function itemFor(array $resource, string $category, string $joinTable): ?InventoryItem
    {
        $externalId = (string) ($resource['id'] ?? '');
        $name = (string) ($resource['name'] ?? '');

        if ($name === '' && is_string($resource['ip'] ?? null) && $resource['ip'] !== '') {
            $name = $resource['ip'];
        }

        $location = $this->locationOf($resource);
        $type = $this->typeOf($resource);

        // Volumes are the one section whose pricing keys by location
        // alone — no type, and provider_type stays empty.
        if ($externalId === '' || $name === '' || $location === null || ($type === null && $joinTable !== 'volumes')) {
            return null;
        }

        $sizeGb = null;

        if ($joinTable === 'volumes') {
            $sizeGb = is_numeric($resource['size'] ?? null) ? (int) $resource['size'] : null;
        }

        $this->priceJoins[$externalId] = [$joinTable, $type ?? '', $location, $sizeGb];

        return new InventoryItem(
            externalId: $externalId,
            category: $category,
            name: $name,
            providerType: $type,
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function typeOf(array $resource): ?string
    {
        if (is_string($resource['type'] ?? null) && $resource['type'] !== '') {
            return $resource['type'];
        }

        foreach (['server_type', 'load_balancer_type'] as $key) {
            if (is_array($resource[$key] ?? null) && is_string($resource[$key]['name'] ?? null)) {
                return $resource[$key]['name'];
            }
        }

        return null;
    }

    /**
     * The location a pricing entry is keyed by: the resource's own
     * location, its datacenter's location, or its home location.
     *
     * @param  array<string, mixed>  $resource
     */
    private function locationOf(array $resource): ?string
    {
        foreach (['location', 'home_location'] as $key) {
            if (is_array($resource[$key] ?? null) && is_string($resource[$key]['name'] ?? null)) {
                return $resource[$key]['name'];
            }
        }

        if (is_array($resource['datacenter'] ?? null)
            && is_array($resource['datacenter']['location'] ?? null)
            && is_string($resource['datacenter']['location']['name'] ?? null)
        ) {
            return $resource['datacenter']['location']['name'];
        }

        return null;
    }

    /**
     * The catalog's monthly net minor units for one pricing join, or
     * null when the join does not match — unknown, never inferred.
     * Volumes are priced per GB: the exact per-GB fraction times the
     * volume size, rounded once, half-even.
     *
     * @param  array<string, mixed>|null  $pricing
     * @param  array{0: string, 1: string, 2: string, 3: ?int}  $join
     * @param  list<string>  $warnings
     */
    private function catalogMinor(?array $pricing, array $join, array &$warnings): ?int
    {
        if ($pricing === null) {
            return null;
        }

        [$table, $type, $location, $sizeGb] = $join;

        $entries = is_array($pricing['pricing'][$table] ?? null) ? $pricing['pricing'][$table] : [];

        foreach ($entries as $entry) {
            if (! is_array($entry)
                || ($entry['location'] ?? null) !== $location
                || ($type !== '' && ($entry['type'] ?? null) !== $type)
            ) {
                continue;
            }

            if ($sizeGb !== null) {
                return $this->volumeMinor($entry, $sizeGb);
            }

            $net = is_array($entry['price_monthly']['net'] ?? null) ? null : ($entry['price_monthly']['net'] ?? null);

            if (is_string($net) || is_numeric($net)) {
                return $this->rationalMinor((string) $net)?->roundHalfEven();
            }
        }

        return null;
    }

    /**
     * Exact per-GB price times the volume's size in GB: the per-GB net
     * fraction becomes an exact Rational of minor units, is multiplied
     * by the integer size, and rounds once, half-even. The rounding
     * lands on the product, never on the per-GB rate.
     *
     * @param  array<string, mixed>  $entry
     */
    private function volumeMinor(array $entry, int $sizeGb): ?int
    {
        $net = is_array($entry['price_per_gb_month']['net'] ?? null) ? null : ($entry['price_per_gb_month']['net'] ?? null);

        if (! (is_string($net) || is_numeric($net))) {
            return null;
        }

        return $this->rationalMinor((string) $net)
            ?->multiply($sizeGb)
            ->roundHalfEven();
    }

    /**
     * A decimal amount (Hetzner sends many fractional digits, e.g.
     * "4.9900000000") as an exact Rational of minor units, or null
     * when the shape is not a plain non-negative decimal. Rounding is
     * the caller's single, explicit decision.
     */
    private function rationalMinor(string $price): ?Rational
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,10}))?$/', trim($price), $matches) !== 1) {
            return null;
        }

        $fractionDigits = strlen($matches[2] ?? '');
        $whole = (int) $matches[1];
        $fraction = (int) ($matches[2] ?? '0');

        if ($fractionDigits === 0) {
            return new Rational($whole * 100);
        }

        return new Rational(
            $whole * 100 * 10 ** $fractionDigits + $fraction * 100,
            10 ** $fractionDigits,
        );
    }

    /**
     * The catalog currency, or null when it is not a usable ISO code —
     * a price without a currency is unknown, not guessed.
     *
     * @param  array<string, mixed>|null  $pricing
     */
    private function currencyOf(?array $pricing): ?string
    {
        $currency = $pricing['currency'] ?? null;

        return is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) === 1
            ? mb_strtoupper($currency)
            : null;
    }

    /**
     * Every page of one Hetzner collection. `meta.pagination` names
     * the last page; a missing or contradictory count ends the walk
     * after the current page, and the hard cap keeps a misbehaving
     * total from spinning forever.
     *
     * @param  list<string>  $warnings
     * @return array{0: list<array<string, mixed>>, 1: bool} the entries
     *                                                       plus whether the walk was cut short
     */
    private function collect(HetznerCloudApi $api, string $path, string $collectionKey, array &$warnings): array
    {
        $resources = [];
        $truncated = false;
        $page = 1;
        $lastPage = 1;

        do {
            $body = $api->get($path, ['page' => $page, 'per_page' => self::PER_PAGE]);

            $collection = is_array($body[$collectionKey] ?? null) ? $body[$collectionKey] : [];

            foreach ($collection as $resource) {
                if (is_array($resource)) {
                    $resources[] = $resource;
                }
            }

            $pagination = is_array($body['meta']['pagination'] ?? null) ? $body['meta']['pagination'] : [];

            if (isset($pagination['last_page']) && is_numeric($pagination['last_page'])) {
                $lastPage = max(1, (int) $pagination['last_page']);
            } else {
                // No readable page count: walking on would be a guess.
                // Stop here, but say so — silent truncation would leave
                // the batch complete-looking while resources are lost.
                $lastPage = $page;
                $truncated = true;
                $warnings[] = sprintf('listing [%s] gave no readable page count; stopped after page %d.', $path, $page);
            }

            $page++;
        } while ($page <= min($lastPage, self::MAX_PAGES));

        if ($lastPage > self::MAX_PAGES) {
            $warnings[] = sprintf('listing [%s] has more than %d pages; the rest was not read.', $path, self::MAX_PAGES);
            $truncated = true;
        }

        return [$resources, $truncated];
    }
}
