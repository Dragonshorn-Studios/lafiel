<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\Models\ProviderAccount;

/**
 * Disconnect a provider account. The database cascades wipe its
 * credentials, discovered services, and sync history — the UI must
 * confirm that before calling this.
 */
class DeleteProviderAccount
{
    public function delete(ProviderAccount $account): void
    {
        $account->delete();
    }
}
