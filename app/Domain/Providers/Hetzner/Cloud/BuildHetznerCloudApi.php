<?php

namespace App\Domain\Providers\Hetzner\Cloud;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use Illuminate\Validation\ValidationException;

/**
 * Builds one Hetzner Cloud client from a decrypted credential payload.
 * Deliberately not final so tests can hand out a fake client through
 * the same seam.
 */
class BuildHetznerCloudApi
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): HetznerCloudApi
    {
        try {
            HetznerCloudCredentialSchema::assertValid($payload);
        } catch (ValidationException $exception) {
            // A payload the schema rejects is malformed at rest — a
            // permanent, human-fixable condition, not an outage.
            throw new InvalidCredentialsException('Stored Hetzner Cloud credential payload does not match schema version '.HetznerCloudCredentialSchema::SCHEMA_VERSION.'.', previous: $exception);
        }

        return new HttpHetznerCloudApi((string) $payload['api_token']);
    }
}
