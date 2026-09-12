<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Models\ProviderAccount;

/**
 * Accounts whose usage capability is supported but not currently
 * healthy — the "metered usage unavailable" condition. The Overview's
 * data-quality card renders one line per gap, so a fixed-subscription
 * total is never mistaken for a complete provider picture.
 */
class UsageGaps
{
    /**
     * @return list<array{account: string}>
     */
    public function all(): array
    {
        $gaps = [];

        $accounts = ProviderAccount::query()
            ->where('enabled', true)
            ->whereHas('capabilityStates', fn ($query) => $query
                ->where('capability_key', ProviderCapability::Usage->value)
                ->where('supported', true)
                ->where('healthy', false))
            ->orderBy('display_name')
            ->get();

        foreach ($accounts as $account) {
            $gaps[] = ['account' => $account->display_name];
        }

        return $gaps;
    }
}
