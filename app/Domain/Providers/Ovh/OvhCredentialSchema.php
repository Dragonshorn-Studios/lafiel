<?php

namespace App\Domain\Providers\Ovh;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
use App\Domain\Providers\Dtos\CredentialHelpStep;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shape of the OVH credential payload stored in
 * `provider_credentials.payload` (`schema_version` 1): the three
 * OVHcloud API credentials plus the endpoint name. Only read access is
 * ever requested for these keys (see docs/integrations.md — credentials
 * are created out-of-band by the user); the whitelist keeps a typo
 * from pointing the account at a different OVH platform.
 */
final class OvhCredentialSchema implements CredentialSchema
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const ENDPOINTS = [
        'ovh-eu',
        'ovh-ca',
        'ovh-us',
        'kimsufi-eu',
        'kimsufi-ca',
        'soyoustart-eu',
        'soyoustart-ca',
    ];

    /**
     * Display label and API host per endpoint. The host also builds the
     * credential-creation URLs shown in the setup guide
     * (`{host}/createApp/`, `{host}/createToken/`).
     *
     * @var array<string, array{label: string, host: string}>
     */
    public const ENDPOINT_INFO = [
        'ovh-eu' => ['label' => 'OVHcloud Europe', 'host' => 'eu.api.ovh.com'],
        'ovh-ca' => ['label' => 'OVHcloud Canada', 'host' => 'ca.api.ovh.com'],
        'ovh-us' => ['label' => 'OVHcloud US', 'host' => 'api.us.ovhcloud.com'],
        'kimsufi-eu' => ['label' => 'Kimsufi Europe', 'host' => 'eu.api.kimsufi.com'],
        'kimsufi-ca' => ['label' => 'Kimsufi Canada', 'host' => 'ca.api.kimsufi.com'],
        'soyoustart-eu' => ['label' => 'So you Start Europe', 'host' => 'eu.api.soyoustart.com'],
        'soyoustart-ca' => ['label' => 'So you Start Canada', 'host' => 'ca.api.soyoustart.com'],
    ];

    public static function label(): string
    {
        return 'OVHcloud';
    }

    public static function help(): string
    {
        return 'Lafiel needs three OVHcloud API credentials. The Application key (AK) and Application secret (AS) identify your application; the Consumer key (CK) delegates your account\'s rights to it. All three come from the same OVHcloud region — credentials only work on the platform that issued them.';
    }

    public static function helpUrl(): string
    {
        return 'https://help.ovhcloud.com/csm/de-api-api-rights-delegation?id=kb_article_view&sysparm_article=KB0068603';
    }

    /**
     * @return list<CredentialHelpStep>
     */
    public static function helpSteps(): array
    {
        return [
            new CredentialHelpStep(
                'Pick your account\'s region above, then create an application in its API console — it issues the Application key and Application secret:',
                lines: array_map(
                    fn (string $endpoint, array $info): string => $info['label'].' ('.$endpoint.') — https://'.$info['host'].'/createApp/',
                    array_keys(self::ENDPOINT_INFO),
                    self::ENDPOINT_INFO,
                ),
            ),
            new CredentialHelpStep(
                'Generate the Consumer key, which delegates rights to the application. The console\'s createToken/ page on the same host (for example https://eu.api.ovh.com/createToken/) issues all three keys in one pass; for an existing application, request one programmatically:',
                lines: [
                    'POST https://eu.api.ovh.com/1.0/auth/credential',
                    'Header: X-Ovh-Application: <application key>',
                    'Body: {"accessRules": [{"method": "GET", "path": "/me"}, …]} — one rule per GET right below',
                ],
            ),
            new CredentialHelpStep(
                'The request answers with a consumerKey and a validationUrl. Open the validationUrl while signed into OVHcloud and approve it — the key stays pendingValidation until then.',
            ),
            new CredentialHelpStep(
                'Delegate only read-only rights — GET on:',
                lines: [
                    '/me — identity and the connection test',
                    '/services and /services/* — inventory and contracted billing',
                    '/service/* — renewal fallback',
                    '/order/catalog/formatted/* — last-resort fallback pricing',
                    '/me/bill* — invoice history',
                ],
            ),
            new CredentialHelpStep(
                'Paste the three keys here and use Test credentials before saving — it runs the same /me probe a saved account would.',
            ),
        ];
    }

    public static function summary(array $payload): string
    {
        $endpoint = (string) ($payload['endpoint'] ?? '');

        return isset(self::ENDPOINT_INFO[$endpoint])
            ? self::ENDPOINT_INFO[$endpoint]['label'].' ('.$endpoint.')'
            : ($endpoint !== '' ? $endpoint : '—');
    }

    /**
     * @return list<CredentialField>
     */
    public static function fields(): array
    {
        return [
            new CredentialField('endpoint', 'Endpoint', CredentialField::TYPE_SELECT, options: self::endpointOptions(), placeholder: 'Choose the OVHcloud region…'),
            new CredentialField('application_key', 'Application key'),
            new CredentialField('application_secret', 'Application secret', CredentialField::TYPE_PASSWORD),
            new CredentialField('consumer_key', 'Consumer key', CredentialField::TYPE_PASSWORD),
        ];
    }

    /**
     * Validation rules for the settings form. Secret fields are always
     * entered fresh; stored values are never rendered back.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', Rule::in(self::ENDPOINTS)],
            'application_key' => ['required', 'string', 'max:255'],
            'application_secret' => ['required', 'string', 'max:255'],
            'consumer_key' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Normalized credential payload for storage.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    public static function payload(array $validated): array
    {
        return [
            'endpoint' => trim((string) $validated['endpoint']),
            'application_key' => trim((string) $validated['application_key']),
            'application_secret' => trim((string) $validated['application_secret']),
            'consumer_key' => trim((string) $validated['consumer_key']),
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

    /**
     * Select choices as endpoint => "Label (endpoint)" — the stored
     * value stays the raw endpoint key the OVH SDK expects.
     *
     * @return array<string, string>
     */
    private static function endpointOptions(): array
    {
        $options = [];

        foreach (self::ENDPOINTS as $endpoint) {
            $options[$endpoint] = self::ENDPOINT_INFO[$endpoint]['label'].' ('.$endpoint.')';
        }

        return $options;
    }
}
