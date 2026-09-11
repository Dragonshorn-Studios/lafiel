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
 * A failed attempt moves nothing but the attempt time; a partial
 * attempt records the observation but not a success, so the capability
 * reads stale while its last complete data stays untouched.
 */
final class RecordCapabilityStates
{
    /**
     * @param  array<string, CapabilityOutcome>  $outcomes  keyed by capability value; only attempted capabilities appear
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

            if ($outcome !== null) {
                $state->last_attempt_at = $now;
                $state->healthy = $outcome->completeness === BatchCompleteness::Complete;

                if ($outcome->completeness === BatchCompleteness::Complete) {
                    $state->last_success_at = $now;
                }

                if ($outcome->completeness->producedUsableData()) {
                    $state->last_observed_at = $outcome->observedAt ?? $now;
                }
            }

            if ($state->isDirty()) {
                $state->save();
            }
        }
    }
}
