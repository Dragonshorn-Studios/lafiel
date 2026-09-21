<?php

namespace App\Domain\Providers\Contabo;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shape of the Contabo credential payload stored in
 * `provider_credentials.payload` (`schema_version` 1): OAuth2 client
 * credentials. They belong to a dedicated API user whose custom role
 * carries READ scope only — provisioning rights are never requested,
 * because the API supporting mutations is not a reason to hold them
 * (docs/integrations.md).
 */
final class ContaboCredentialSchema implements CredentialSchema
{
    public const SCHEMA_VERSION = 1;

    public static function label(): string
    {
        return 'Contabo';
    }

    public static function help(): string
    {
        return 'Create a Contabo API user with a custom role that carries only READ scope (Data: read, Jobs: read), then note its client id and secret. Lafiel never calls a mutating Contabo endpoint. Storage VPS is unsupported by the Compute API and is not discovered.';
    }

    public static function helpUrl(): string
    {
        return 'https://api.contabo.com/';
    }

    public static function helpSteps(): array
    {
        return [];
    }

    public static function summary(array $payload): string
    {
        return 'OAuth2 client';
    }

    public static function fields(): array
    {
        return [
            new CredentialField('client_id', 'Client ID'),
            new CredentialField('client_secret', 'Client secret', CredentialField::TYPE_PASSWORD),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    public static function payload(array $validated): array
    {
        return [
            'client_id' => trim((string) $validated['client_id']),
            'client_secret' => trim((string) $validated['client_secret']),
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
