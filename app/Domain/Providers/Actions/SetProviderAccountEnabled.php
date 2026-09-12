<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\Models\ProviderAccount;

/**
 * Enable or disable a provider account. A disabled account refuses new
 * syncs (the orchestrator finishes the run as cancelled) and keeps its
 * stored history.
 */
class SetProviderAccountEnabled
{
    public function set(ProviderAccount $account, bool $enabled): void
    {
        $account->enabled = $enabled;
        $account->save();
    }
}
