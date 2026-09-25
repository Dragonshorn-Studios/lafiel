<?php

namespace App\Domain\Providers\OpenRouter;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
use Illuminate\Support\Facades\Validator;

/**
 * Shape of the OpenRouter credential payload stored in
 * `provider_credentials.payload`: one API key.
 */
final class OpenRouterCredentialSchema implements CredentialSchema
{
    public const SCHEMA_VERSION = 1;

    public static function label(): string
    {
        return 'OpenRouter';
    }

    public static function help(): string
    {
        return 'Provide an OpenRouter API key. Lafiel queries key usage and credit balance (/api/v1/auth/key and /api/v1/credits) to track AI model spend.';
    }

    public static function helpUrl(): string
    {
        return 'https://openrouter.ai/settings/keys';
    }

    public static function helpSteps(): array
    {
        return [];
    }

    public static function summary(array $payload): string
    {
        return 'API key';
    }

    /**
     * @return list<CredentialField>
     */
    public static function fields(): array
    {
        return [
            new CredentialField('api_key', 'API key', CredentialField::TYPE_PASSWORD, placeholder: 'sk-or-v1-...'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'api_key' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    public static function payload(array $validated): array
    {
        return [
            'api_key' => trim((string) $validated['api_key']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertValid(array $payload): void
    {
        Validator::make($payload, self::rules())->validate();
    }
}
