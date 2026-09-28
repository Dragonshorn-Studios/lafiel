<?php

namespace App\Domain\Providers\Mikrus;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use Illuminate\Validation\ValidationException;

/**
 * Builds one mikr.us client from a decrypted credential payload.
 * Deliberately not final so tests can hand out a fake client through
 * the same seam.
 */
class BuildMikrusApi
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): MikrusApi
    {
        try {
            MikrusCredentialSchema::assertValid($payload);
        } catch (ValidationException $exception) {
            // A payload the schema rejects is malformed at rest — a
            // permanent, human-fixable condition, not an outage. The
            // failing field names (never values) make it fixable.
            $fields = implode(', ', array_keys($exception->errors()));

            throw new InvalidCredentialsException('Stored mikr.us credential payload does not match schema version '.MikrusCredentialSchema::SCHEMA_VERSION." (failing fields: {$fields}).", previous: $exception);
        }

        return new HttpMikrusApi((string) $payload['api_key']);
    }
}
