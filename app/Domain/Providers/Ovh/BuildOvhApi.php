<?php

namespace App\Domain\Providers\Ovh;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use Illuminate\Validation\ValidationException;

/**
 * Builds the read-only API client for a stored credential payload. A
 * payload that no longer matches the schema is a permanent, human-fixable
 * condition — the same category as credentials the provider rejected,
 * never something to retry.
 */
final class BuildOvhApi
{
    /**
     * @param  array<string, mixed>  $payload  decrypted credential payload
     */
    public function build(array $payload): OvhApi
    {
        try {
            OvhCredentialSchema::assertValid($payload);
        } catch (ValidationException) {
            throw new InvalidCredentialsException('Stored OVH credential payload does not match schema version '.OvhCredentialSchema::SCHEMA_VERSION.'.');
        }

        return SdkOvhApi::forPayload($payload);
    }
}
