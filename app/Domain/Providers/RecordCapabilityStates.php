<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Dtos\CapabilitySet;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use Carbon\CarbonImmutable;

/**
 * Upserts the per-capability freshness state after one run. All known
 * capabilities get their support flag from the adapter's capability
 * set; only attempted ones move attempt/success/observation state.
 * `healthy` means the last attempt fully succeeded — a partial or
 * failed attempt marks the capability stale while the last good data
 * stays untouched.
 */
final class RecordCapabilityStates
{
    /**
     * @param  array<string, CapabilityOutcome>  $outcomes  keyed by capability value
     */
    public function record(ProviderAccount $account, CapabilitySet $capabilities, array $outcomes, CarbonImmutable $now): void
    {
        foreach (ProviderCapability::cases() as $capability) {
            $state = ProviderCapabilityState::query()->firstOrNew([
                'provider_account_id' => $account->id,
                'capability_key' => $capability->value,
            ]);

            $state->supported = $capabilities->supports($capability);

            $outcome = $outcomes[$capability->value] ?? null;

            if ($outcome !== null && $outcome->attempted) {
                $state->last_attempt_at = $now;
                $state->healthy = $outcome->completeness === BatchCompleteness::Complete;

                if ($outcome->completeness === BatchCompleteness::Complete) {
                    $state->last_success_at = $now;
                }

                if ($outcome->completeness !== null && $outcome->completeness->producedUsableData()) {
                    $state->last_observed_at = $outcome->observedAt ?? $now;
                }
            }

            if ($state->isDirty()) {
                $state->save();
            }
        }
    }
}
