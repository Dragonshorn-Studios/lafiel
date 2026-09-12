<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;

/**
 * Record that a connection test just proved this credential. The
 * fingerprint uses the same scheme as the sync orchestrator, so a
 * later sync skips re-validation unless the payload changed.
 */
class VerifyCredentials
{
    public function verify(ProviderAccount $account, ProviderCredential $credential): void
    {
        $context = new SyncContext($account, $credential->payload, now());

        $credential->verified_at = now();
        $credential->fingerprint = $context->credentialFingerprint();
        $credential->save();
    }
}
