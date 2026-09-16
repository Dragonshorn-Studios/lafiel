<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\Models\ProviderAccount;

/**
 * Disconnect a provider account. Synced inventory, provider charges,
 * and sync history are wiped first (same reset as Clear synced data);
 * then the account and credentials go. Independent manual charges stay.
 */
class DeleteProviderAccount
{
    public function __construct(private readonly ClearProviderSyncedData $clearProviderSyncedData) {}

    /**
     * @return bool false when a queued or running sync still owns the
     *              account — the wipe would race that run
     */
    public function delete(ProviderAccount $account): bool
    {
        if (! $this->clearProviderSyncedData->clear($account)) {
            return false;
        }

        $account->delete();

        return true;
    }
}
