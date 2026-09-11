<?php

namespace App\Domain\Providers\Dtos;

use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;

/**
 * Everything one adapter call may read: the account, a decrypted copy
 * of its credential payload (in memory only, never logged or stored),
 * and the frozen observation time of the run.
 */
final readonly class SyncContext
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public ProviderAccount $account,
        public array $credentials,
        public CarbonImmutable $now,
    ) {}

    /**
     * Stable, non-reversible fingerprint of the credential payload. Used
     * to detect changed credentials; never reversible to the secret.
     */
    public function credentialFingerprint(): ?string
    {
        if ($this->credentials === []) {
            return null;
        }

        return hash('sha256', (string) json_encode($this->credentials));
    }
}
