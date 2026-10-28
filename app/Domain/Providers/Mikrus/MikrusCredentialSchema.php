<?php

namespace App\Domain\Providers\Mikrus;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
use App\Domain\Providers\Dtos\CredentialHelpStep;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shape of the mikr.us credential payload stored in
 * `provider_credentials.payload` (`schema_version` 2): one account API
 * key plus the default server. The key reads the account's inventory;
 * mikr.us exposes no billing endpoints, so no price data is ever
 * requested (docs/integrations.md). The default server is required
 * because the API expects the `srv` variable on every call —
 * requests without one answer 400 "server not provided" (issue #89) —
 * and it rides on the account-level calls the adapter makes.
 */
final class MikrusCredentialSchema implements CredentialSchema
{
    public const SCHEMA_VERSION = 2;

    public static function label(): string
    {
        return 'Mikr.us';
    }

    public static function help(): string
    {
        return 'Generate an API key for your mikr.us account in the panel, on the API page. The key lets Lafiel read your server inventory — names and expiration dates. mikr.us exposes no billing API, so prices stay manual (attach them from the subscription library or the cost form). mikr.us expects a server name on every API call, so name one of your servers as the default for account-level reads.';
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
            new CredentialHelpStep(
                body: 'Copy the name of one of your servers from the panel (mikr.us panel → "Moje serwery") and enter it as the default server — every API call needs it.',
                lines: ['The default server backs the connection check and account-level reads; individual servers are still read under their own names.'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function summary(array $payload): string
    {
        $server = trim((string) ($payload['default_server'] ?? ''));

        return $server === '' ? 'API key' : 'API key · default server '.$server;
    }

    public static function fields(): array
    {
        return [
            new CredentialField('api_key', 'API key', CredentialField::TYPE_PASSWORD),
            new CredentialField('default_server', 'Default server', placeholder: 'emil100'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'api_key' => ['required', 'string', 'max:255'],
            'default_server' => ['required', 'string', 'max:255'],
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
            'default_server' => trim((string) $validated['default_server']),
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
