<?php

namespace App\Domain\Providers\Contabo;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use Illuminate\Validation\ValidationException;

/**
 * Builds one Contabo client from a decrypted credential payload.
 * Deliberately not final so tests can hand out a fake client through
 * the same seam.
 */
class BuildContaboApi
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): ContaboApi
    {
        try {
            ContaboCredentialSchema::assertValid($payload);
        } catch (ValidationException $exception) {
            // A payload the schema rejects is malformed at rest — a
            // permanent, human-fixable condition, not an outage.
            throw new InvalidCredentialsException('Stored Contabo credential payload does not match schema version '.ContaboCredentialSchema::SCHEMA_VERSION.'.', previous: $exception);
        }

        return new HttpContaboApi((string) $payload['client_id'], (string) $payload['client_secret']);
    }
}
