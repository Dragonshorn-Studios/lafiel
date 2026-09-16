<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Costs\Models\CostItem;
use App\Domain\History\TakeSnapshot;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use Illuminate\Support\Facades\DB;

/**
 * Wipe one account's discovered inventory, provider-sourced charges,
 * capability health, and sync history while keeping the connection
 * and credentials. Independent manual charges (their own logical
 * charge key, not an overlay of a discovered service) stay.
 *
 * Inventory lifecycle never hard-deletes a service; this is an
 * operator reset after a test sync, not a lifecycle transition.
 */
class ClearProviderSyncedData
{
    public function __construct(private readonly TakeSnapshot $takeSnapshot) {}

    /**
     * @return bool false when a queued or running sync still owns the
     *              account — the wipe would race that run
     */
    public function clear(ProviderAccount $account): bool
    {
        $blocked = $account->syncRuns()
            ->whereIn('status', [SyncStatus::Queued, SyncStatus::Running])
            ->exists();

        if ($blocked) {
            return false;
        }

        DB::transaction(function () use ($account): void {
            $this->deleteProviderCharges($account);

            Service::query()->where('provider_account_id', $account->id)->delete();
            SyncRun::query()->where('provider_account_id', $account->id)->delete();
            ProviderCapabilityState::query()->where('provider_account_id', $account->id)->delete();

            $account->last_attempt_at = null;
            $account->last_success_at = null;
            $account->save();
        });

        $this->takeSnapshot->capture();

        return true;
    }

    /**
     * Provider facts live under `{provider}:account:{id}:charge:`.
     * Manual overlays of discovered services use a disjoint key but
     * still cover those services — they go with the services.
     */
    private function deleteProviderCharges(ProviderAccount $account): void
    {
        $serviceIds = Service::query()
            ->where('provider_account_id', $account->id)
            ->pluck('id');

        $ids = collect();

        if ($serviceIds->isNotEmpty()) {
            $ids = $ids->merge(
                DB::table('cost_item_services')
                    ->whereIn('service_id', $serviceIds)
                    ->pluck('cost_item_id'),
            );
        }

        $prefix = sprintf('%s:account:%d:charge:', $account->provider_key, $account->id);

        $ids = $ids->merge(
            CostItem::query()
                ->where('logical_charge_key', 'like', addcslashes($prefix, '%_\\').'%')
                ->pluck('id'),
        )->unique()->values();

        if ($ids->isNotEmpty()) {
            CostItem::query()->whereIn('id', $ids)->delete();
        }
    }
}
