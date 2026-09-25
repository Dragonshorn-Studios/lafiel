<?php

namespace App\Domain\Providers\Hetzner\Cloud;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shape of the Hetzner Cloud credential payload stored in
 * `provider_credentials.payload` (`schema_version` 1): one project
 * API token with read-only role. A token is project-scoped — multiple
 * projects become multiple provider accounts — and Hetzner Robot is a
 * separate provider with separate credentials (docs/integrations.md).
 */
final class HetznerCloudCredentialSchema implements CredentialSchema
{
    public const SCHEMA_VERSION = 1;

    public static function label(): string
    {
        return 'Hetzner Cloud';
    }

    public static function help(): string
    {
        return 'Create a Hetzner Cloud API token for one project with a read-only role — a token is project-scoped, so each project is its own provider account. Lafiel never calls a mutating Hetzner endpoint; Robot dedicated servers are a separate integration with separate credentials.';
    }

    public static function helpUrl(): string
    {
        return 'https://docs.hetzner.cloud/reference/cloud';
    }

    public static function helpSteps(): array
    {
        return [];
    }

    public static function summary(array $payload): string
    {
        return 'API token';
    }

    public static function fields(): array
    {
        return [
            new CredentialField('api_token', 'API token', CredentialField::TYPE_PASSWORD, placeholder: 'Project API token (read-only)'),
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
