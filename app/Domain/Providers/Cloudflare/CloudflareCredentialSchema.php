<?php

namespace App\Domain\Providers\Cloudflare;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shape of the Cloudflare credential payload stored in
 * `provider_credentials.payload` (`schema_version` 1): one API token.
 * The token is created out-of-band by the user with Billing Read
 * (account subscriptions) and Zone Read (zone discovery and zone
 * subscriptions) — no write permission is ever requested
 * (docs/integrations.md).
 */
final class CloudflareCredentialSchema implements CredentialSchema
{
    public const SCHEMA_VERSION = 1;

    public static function label(): string
    {
        return 'Cloudflare';
    }

    public static function help(): string
    {
        return 'Create a Cloudflare API token with the smallest read-only rights: Billing Read on the account (account subscriptions) and Zone Read (zone discovery and zone subscriptions). Lafiel never calls a mutating Cloudflare endpoint.';
    }

    public static function helpUrl(): string
    {
        return 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/';
    }

    public static function helpSteps(): array
    {
        return [];
    }

    public static function summary(array $payload): string
    {
        return 'API token';
    }

    /**
     * @return list<CredentialField>
     */
    public static function fields(): array
    {
        return [
            new CredentialField('api_token', 'API token', CredentialField::TYPE_PASSWORD, placeholder: 'Cloudflare API token'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'api_token' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    public static function payload(array $validated): array
    {
        return [
            'api_token' => trim((string) $validated['api_token']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public static function assertValid(array $payload): void
    {
        Validator::make($payload, self::rules())->validate();
    }
}
