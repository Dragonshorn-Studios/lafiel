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
     * Keys are sorted first, so the fingerprint does not depend on the
     * payload's key order.
     */
    public function credentialFingerprint(): ?string
    {
        if ($this->credentials === []) {
            return null;
        }

        $payload = $this->credentials;
        $this->sortKeys($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function sortKeys(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->sortKeys($value);
            }
        }
    }
}
