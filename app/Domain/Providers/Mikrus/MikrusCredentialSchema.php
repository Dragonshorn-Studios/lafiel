<?php

namespace App\Domain\Providers\Mikrus;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
use App\Domain\Providers\Dtos\CredentialHelpStep;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shape of the mikr.us credential payload stored in
 * `provider_credentials.payload` (`schema_version` 1): one account API
 * key, generated in the mikr.us panel. The key reads the account's
 * inventory; mikr.us exposes no billing endpoints, so no price data is
 * ever requested (docs/integrations.md).
 */
final class MikrusCredentialSchema implements CredentialSchema
{
    public const SCHEMA_VERSION = 1;

    public static function label(): string
    {
        return 'Mikr.us';
    }

    public static function help(): string
    {
        return 'Generate an API key for your mikr.us account in the panel, on the API page. The key lets Lafiel read your server inventory — names and expiration dates. mikr.us exposes no billing API, so prices stay manual (attach them from the subscription library or the cost form).';
    }

    public static function helpUrl(): string
    {
        return 'https://mikr.us/panel/?a=api';
    }

    public static function helpSteps(): array
    {
        return [
            new CredentialHelpStep(
                body: 'Open the API page in the mikr.us panel and generate a key.',
                lines: ['mikr.us panel → API → "Wygeneruj klucz API"'],
                url: 'https://mikr.us/panel/?a=api',
                urlLabel: 'mikr.us panel — API',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function summary(array $payload): string
    {
        return 'API key';
    }

    public static function fields(): array
    {
        return [
            new CredentialField('api_key', 'API key', CredentialField::TYPE_PASSWORD),
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
     *
     * @throws ValidationException
     */
    public static function assertValid(array $payload): void
    {
        Validator::make($payload, self::rules())->validate();
    }
}
