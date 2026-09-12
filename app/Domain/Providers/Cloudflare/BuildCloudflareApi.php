<?php

namespace App\Domain\Providers\Cloudflare;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use Illuminate\Validation\ValidationException;

/**
 * Builds one Cloudflare client from a decrypted credential payload.
 * Deliberately not final so tests can hand out a fake client through
 * the same seam.
 */
class BuildCloudflareApi
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): CloudflareApi
    {
        try {
            CloudflareCredentialSchema::assertValid($payload);
        } catch (ValidationException $exception) {
            // A payload the schema rejects is malformed at rest — a
            // permanent, human-fixable condition, not an outage.
            throw new InvalidCredentialsException('Stored Cloudflare credential payload does not match schema version '.CloudflareCredentialSchema::SCHEMA_VERSION.'.', previous: $exception);
        }

        return new HttpCloudflareApi((string) $payload['api_token']);
    }
}
