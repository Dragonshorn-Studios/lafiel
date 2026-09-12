<?php

namespace App\Domain\Providers\Cloudflare;

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
use Carbon\CarbonImmutable;

/**
 * Read-only Cloudflare adapter for inventory and fixed subscriptions.
 * Zones are the discovered services (`GET /zones`); the account itself
 * is inventoried too, because account-level subscriptions (Workers,
 * R2, Load Balancing plans) need a service to anchor their charge to.
 *
 * Subscriptions come from `GET /accounts/{id}/subscriptions` and
 * `GET /zones/{id}/subscriptions`. The same subscription id can be
 * reported by both listings — it is kept once, never duplicated. The
 * fixed components of one subscription sum into one recurring cost
 * fact; metered components are not part of this ticket, so the Usage
 * capability is declared and reported partial: fixed subscriptions
 * alone are not a complete Cloudflare total. A free plan's price is a
 * known zero; a missing price or currency field is unknown, never
 * inferred.
 */
final class CloudflareProviderAdapter implements ProviderAdapter
{
    private const PER_PAGE = 50;

    /** A misbehaving page total must not spin the walk forever. */
    private const MAX_PAGES = 100;

    public function __construct(private readonly BuildCloudflareApi $buildApi) {}

    public function validateCredentials(SyncContext $context): CredentialCheck
    {
        try {
            $this->buildApi->build($context->credentials)->get('/user/tokens/verify');
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
            ProviderCapability::Usage,
        ]);
    }

    public function fetchInventory(SyncContext $context): InventoryBatch
    {
        $api = $this->buildApi->build($context->credentials);
        $warnings = [];
        $partial = false;
        $items = [];

        // The account listing is the primary observation: its failure
        // fails the whole phase, like OVH's service listing — the
        // exception propagates and the run keeps its last good data.
        foreach ($this->collect($api, '/accounts', $warnings) as $account) {
            $item = $this->accountItem($account);

            if ($item === null) {
                $partial = true;
                $warnings[] = 'an account without an id or name was skipped.';

                continue;
            }

            $items[] = $item;
        }

        try {
            $zones = $this->collect($api, '/zones', $warnings);
        } catch (InvalidCredentialsException $exception) {
            throw $exception;
        } catch (ProviderException $exception) {
            $partial = true;
            $zones = [];
            $warnings[] = 'zone listing failed: '.$exception->getMessage();
        }

        foreach ($zones as $zone) {
            $item = $this->zoneItem($zone);

            if ($item === null) {
                $partial = true;
                $warnings[] = 'a zone without an id or name was skipped.';

                continue;
            }

            $items[] = $item;
        }

        return new InventoryBatch(
            completeness: $partial ? BatchCompleteness::Partial : BatchCompleteness::Complete,
            observedAt: $context->now,
            sourceRef: 'cloudflare:/accounts,/zones',
            items: $items,
            warnings: $warnings,
        );
    }

    public function fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
    {
        $api = $this->buildApi->build($context->credentials);
        $warnings = [];
        $facts = [];
        $degraded = false;
        $seenSubscriptionIds = [];
        $duplicates = 0;
        $unknownPrices = 0;
        $skippedStates = 0;

        foreach ($inventory->items as $item) {
            $path = match ($item->category) {
                'account' => "/accounts/{$item->externalId}/subscriptions",
                'dns' => "/zones/{$item->externalId}/subscriptions",
                default => null,
            };

            if ($path === null) {
                continue;
            }

            try {
                $subscriptions = $this->collect($api, $path, $warnings);
            } catch (InvalidCredentialsException $exception) {
                throw $exception;
            } catch (ProviderException $exception) {
                $degraded = true;
                $warnings[] = sprintf('subscriptions for [%s] failed: %s', $item->name, $exception->getMessage());

                continue;
            }

            foreach ($subscriptions as $subscription) {
                $subscriptionId = (string) ($subscription['id'] ?? '');

                if ($subscriptionId === '') {
                    $degraded = true;
                    $warnings[] = sprintf('subscriptions for [%s] carried an entry without an id.', $item->name);

                    continue;
                }

                // Account and zone listings can both report one
                // subscription; the charge is kept once.
                if (isset($seenSubscriptionIds[$subscriptionId])) {
                    $duplicates++;

                    continue;
                }

                $seenSubscriptionIds[$subscriptionId] = true;

                $fact = $this->factFor($subscription, $item, $context, $unknownPrices, $skippedStates);

                if ($fact !== null) {
                    $facts[] = $fact;
                }
            }
        }

        if ($duplicates > 0) {
            $warnings[] = sprintf('%d duplicate subscription(s) across the account and zone listings were kept once.', $duplicates);
        }

        if ($unknownPrices > 0) {
            $warnings[] = sprintf('%d subscription(s) have no fixed price or currency; their charges stay unknown.', $unknownPrices);
        }

        if ($skippedStates > 0) {
            $warnings[] = sprintf('%d subscription(s) in a state other than paid or free were skipped.', $skippedStates);
        }

        $warnings[] = 'metered usage is unavailable — fixed subscriptions only; the Cloudflare total is not complete.';

        $completeness = $degraded ? BatchCompleteness::Partial : BatchCompleteness::Complete;

        return new CostFactBatch(
            completeness: $completeness,
            observedAt: $context->now,
            sourceRef: 'cloudflare:subscriptions',
            facts: $facts,
            warnings: $warnings,
            capabilityCompleteness: [
                ProviderCapability::Subscriptions->value => $completeness,
                // Metered usage exists as a capability but is not read:
                // the run stays partial so no fixed-subscription sum is
                // ever mistaken for a complete Cloudflare total.
                ProviderCapability::Usage->value => BatchCompleteness::Partial,
            ],
            // This batch represents the subscription observation only.
            // Usage is declared and partial, but it is not what this
            // batch observed — so a cancelled subscription can still
            // be recognized (and its charge ended) on a complete run.
            reportedCapabilities: [ProviderCapability::Subscriptions],
        );
    }

    /**
     * One subscription in, one recurring cost fact out — or nothing,
     * when the subscription's state is not one we can price.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function factFor(array $subscription, InventoryItem $service, SyncContext $context, int &$unknownPrices, int &$skippedStates): ?CostFact
    {
        $state = mb_strtolower((string) ($subscription['state'] ?? ''));

        if (! in_array($state, ['paid', 'free'], true)) {
            $skippedStates++;

            return null;
        }

        $ratePlan = is_array($subscription['rate_plan'] ?? null) ? $subscription['rate_plan'] : [];
        $currency = isset($ratePlan['currency']) && is_string($ratePlan['currency']) ? mb_strtoupper(trim($ratePlan['currency'])) : '';

        // The fixed components sum into one price; metered components
        // carry no fixed price and simply add nothing.
        $minorTotal = 0;
        $hasFixedPrice = false;

        foreach (is_array($ratePlan['components'] ?? null) ? $ratePlan['components'] : [] as $component) {
            if (! is_array($component) || ! array_key_exists('price', $component)) {
                continue;
            }

            $price = $component['price'];

            if (! is_numeric($price)) {
                continue;
            }

            $componentMinor = $this->toMinor((string) $price);

            if ($componentMinor === null) {
                continue;
            }

            $minorTotal += $componentMinor;
            $hasFixedPrice = true;
        }

        $amount = null;

        if ($hasFixedPrice && $currency !== '') {
            $amount = Money::ofMinor($minorTotal, $currency);
        } else {
            // A price with no currency, or no fixed price at all, is
            // honestly unknown — never inferred from the plan name.
            $unknownPrices++;
        }

        $currentPeriod = is_array($subscription['current_period'] ?? null) ? $subscription['current_period'] : [];

        return new CostFact(
            sourceRef: sprintf('cf:subscription:%s', (string) $subscription['id']),
            serviceExternalIds: [$service->externalId],
            sourceKind: SourceKind::Subscription,
            chargeKind: ChargeKind::RecurringFixed,
            period: $this->periodFor($subscription['frequency'] ?? null),
            evidenceState: EvidenceState::Actual,
            amount: $amount,
            validFrom: $this->dateOr($currentPeriod['start'] ?? null, $context->now),
            taxBasis: TaxBasis::Unknown,
            renewsAt: $this->dateOr($currentPeriod['end'] ?? null, null),
            autoRenew: (bool) ($subscription['auto_renew'] ?? false),
            allocationState: AllocationState::Direct,
            notes: isset($ratePlan['public_name']) && is_string($ratePlan['public_name']) && $ratePlan['public_name'] !== ''
                ? 'Rate plan: '.$ratePlan['public_name']
                : null,
        );
    }

    /**
     * Subscription frequency to canonical period; anything unmapped
     * stays unknown instead of guessing a cadence.
     */
    private function periodFor(mixed $frequency): Period
    {
        return match (mb_strtolower((string) $frequency)) {
            'monthly' => Period::Monthly,
            'quarterly' => Period::Quarterly,
            'annually', 'yearly' => Period::Annual,
            default => Period::Unknown,
        };
    }

    /**
     * Decimal string to integer minor units. `XXX` ("no currency") is
     * a parse-only placeholder so the Money regex stays the single
     * source of truth; a value Money rejects (more than two
     * fractional digits) contributes nothing and leaves the
     * subscription unpriced rather than inventing an amount.
     */
    private function toMinor(string $price): ?int
    {
        try {
            return Money::ofString(trim($price), 'XXX')->amountMinor;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return ?CarbonImmutable null when the date is missing or
     *                          unparseable — the caller decides the fallback
     */
    private function dateOr(mixed $value, ?CarbonImmutable $fallback): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return new CarbonImmutable($value);
        } catch (\Exception) {
            return $fallback;
        }
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function accountItem(array $account): ?InventoryItem
    {
        $externalId = (string) ($account['id'] ?? '');
        $name = (string) ($account['name'] ?? '');

        if ($externalId === '' || $name === '') {
            return null;
        }

        return new InventoryItem(
            externalId: $externalId,
            category: 'account',
            name: $name,
            providerType: 'account',
        );
    }

    /**
     * @param  array<string, mixed>  $zone
     */
    private function zoneItem(array $zone): ?InventoryItem
    {
        $externalId = (string) ($zone['id'] ?? '');
        $name = (string) ($zone['name'] ?? '');

        if ($externalId === '' || $name === '') {
            return null;
        }

        return new InventoryItem(
            externalId: $externalId,
            category: 'dns',
            name: $name,
            providerType: 'zone',
        );
    }

    /**
     * Every page of one Cloudflare collection. The envelope's
     * `result_info` names the page count; a missing or contradictory
     * count ends the walk after the current page, and the hard cap
     * keeps a misbehaving total from spinning forever.
     *
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function collect(CloudflareApi $api, string $path, array &$warnings): array
    {
        $items = [];
        $page = 1;
        $totalPages = 1;

        do {
            $envelope = $api->get($path, ['page' => $page, 'per_page' => self::PER_PAGE]);

            $result = $envelope['result'] ?? [];

            if (is_array($result)) {
                foreach ($result as $entry) {
                    if (is_array($entry)) {
                        $items[] = $entry;
                    }
                }
            }

            $info = is_array($envelope['result_info'] ?? null) ? $envelope['result_info'] : [];
            $totalPages = isset($info['total_pages']) && is_numeric($info['total_pages'])
                ? max(1, (int) $info['total_pages'])
                : $page;
            $page++;
        } while ($page <= min($totalPages, self::MAX_PAGES));

        if ($totalPages > self::MAX_PAGES) {
            $warnings[] = sprintf('listing [%s] has more than %d pages; the rest was not read.', $path, self::MAX_PAGES);
        }

        return $items;
    }
}
