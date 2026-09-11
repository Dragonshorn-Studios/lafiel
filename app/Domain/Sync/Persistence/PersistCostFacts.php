<?php

namespace App\Domain\Sync\Persistence;

use App\Domain\Costs\Enums\AmountState;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Dtos\CostFact;
use App\Domain\Providers\Dtos\CostFactBatch;
use Carbon\CarbonImmutable;

/**
 * Idempotent persistence of one cost-fact batch. Identity mirrors the
 * manual slice: `logical_charge_key` groups all evidence for one
 * provider charge (`{provider}:account:{id}:charge:{sourceRef}` — the
 * account is part of the key because a source reference is only stable
 * within one account), `identity_key` pins one version from one valid
 * date.
 *
 * A price-affecting change never rewrites the open fact: it is closed
 * and a new version opens under the same logical charge, so history
 * keeps every price. Evidence upgrades (quote to actual), observation
 * times, and renewal dates update in place. Manual rows are never
 * touched — they live under a different key namespace and win through
 * the projector's precedence, not through here.
 */
final class PersistCostFacts
{
    /**
     * @param  array<string, Service>  $servicesByExternalId
     */
    public function persist(string $providerKey, int $accountId, CostFactBatch $batch, array $servicesByExternalId): PersistedCostFacts
    {
        $created = 0;
        $superseded = 0;
        $updated = 0;
        $renewals = 0;

        foreach ($batch->facts as $fact) {
            $logicalKey = sprintf('%s:account:%d:charge:%s', $providerKey, $accountId, $fact->sourceRef);
            $open = CostItem::query()
                ->where('logical_charge_key', $logicalKey)
                ->whereNull('valid_to')
                ->first();

            if ($open === null) {
                $item = $this->createVersion($logicalKey, $fact, $batch);
                $this->attachCoverage($item, $fact, $servicesByExternalId);
                $this->writeRenewalDate($item, $fact);
                $created++;
                $renewals += $fact->renewsAt !== null ? 1 : 0;

                continue;
            }

            if ($this->priceAffectingChange($open, $fact)) {
                $closed = $open;
                // valid_to is a date column: the charge's last day is the
                // day before the new version starts.
                $closed->valid_to = $fact->validFrom->subDay()->startOfDay();
                $closed->save();

                $item = $this->createVersion($logicalKey, $fact, $batch);
                $this->moveRenewals($closed, $item);
                $this->attachCoverage($item, $fact, $servicesByExternalId);
                $this->writeRenewalDate($item, $fact);
                $superseded++;
                $created++;
                $renewals += $fact->renewsAt !== null ? 1 : 0;

                continue;
            }

            $open->source_kind = $fact->sourceKind;
            $open->evidence_state = $fact->evidenceState;
            $open->tax_basis = $fact->taxBasis;
            $open->observed_at = $batch->observedAt;
            if ($open->isDirty()) {
                $open->save();
            }

            $this->writeRenewalDate($open, $fact);
            $updated++;
            $renewals += $fact->renewsAt !== null ? 1 : 0;
        }

        return new PersistedCostFacts($created, $superseded, $updated, $renewals);
    }

    /**
     * Amount, currency, period, and charge kind define the price. When
     * any of them moves — or an unknown amount becomes known — the open
     * version closes and a new one opens.
     */
    private function priceAffectingChange(CostItem $open, CostFact $fact): bool
    {
        if ($open->period !== $fact->period || $open->charge_kind !== $fact->chargeKind) {
            return true;
        }

        if ($fact->amount === null) {
            return $open->amount_state !== AmountState::Unknown;
        }

        return $open->amount_state !== AmountState::Known
            || $open->amount_minor !== $fact->amount->amountMinor
            || $open->currency !== $fact->amount->currency;
    }

    private function createVersion(string $logicalKey, CostFact $fact, CostFactBatch $batch): CostItem
    {
        $known = $fact->amount !== null;

        return CostItem::create([
            'identity_key' => $this->nextIdentity($logicalKey, $fact->validFrom),
            'logical_charge_key' => $logicalKey,
            'source_kind' => $fact->sourceKind,
            'charge_kind' => $fact->chargeKind,
            'period' => $fact->period,
            'amount_minor' => $known ? $fact->amount->amountMinor : null,
            'currency' => $known ? $fact->amount->currency : null,
            'amount_state' => $known ? AmountState::Known : AmountState::Unknown,
            'evidence_state' => $fact->evidenceState,
            'tax_basis' => $fact->taxBasis,
            'valid_from' => $fact->validFrom,
            'observed_at' => $batch->observedAt,
        ]);
    }

    /**
     * Versions of one charge may start on the same day, so the identity
     * key carries a version suffix once the plain date key is taken —
     * the same scheme the manual actions use.
     */
    private function nextIdentity(string $logicalKey, CarbonImmutable $validFrom): string
    {
        $base = sprintf('%s:from:%s', $logicalKey, $validFrom->format('Y-m-d'));
        $identity = $base;
        $version = 1;

        while (CostItem::query()->where('identity_key', $identity)->exists()) {
            $version++;
            $identity = $base.':v'.$version;
        }

        return $identity;
    }

    /**
     * @param  array<string, Service>  $servicesByExternalId
     */
    private function attachCoverage(CostItem $item, CostFact $fact, array $servicesByExternalId): void
    {
        $serviceIds = [];

        foreach ($fact->serviceExternalIds as $externalId) {
            $service = $servicesByExternalId[$externalId] ?? null;

            if ($service !== null) {
                $serviceIds[] = $service->id;
            }
        }

        if ($serviceIds !== []) {
            $item->services()->syncWithoutDetaching($serviceIds);
        }
    }

    /**
     * Renewals always point at the newest version of the charge.
     */
    private function moveRenewals(CostItem $closed, CostItem $newest): void
    {
        Renewal::query()
            ->where('cost_item_id', $closed->id)
            ->update(['cost_item_id' => $newest->id]);
    }

    /**
     * A fact without a renewal date leaves an existing renewal
     * untouched: the absence of a date in one observation is not the
     * news that the renewal is gone.
     */
    private function writeRenewalDate(CostItem $item, CostFact $fact): void
    {
        if ($fact->renewsAt === null) {
            return;
        }

        Renewal::query()->updateOrCreate([
            'cost_item_id' => $item->id,
        ], [
            'renews_at' => $fact->renewsAt,
            'auto_renew' => $fact->autoRenew,
        ]);
    }
}
