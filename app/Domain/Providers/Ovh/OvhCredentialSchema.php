<?php

namespace App\Domain\Providers\Ovh;

use App\Domain\Providers\CredentialSchema;
use App\Domain\Providers\Dtos\CredentialField;
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

    public static function label(): string
    {
        return 'OVHcloud';
    }

    public static function help(): string
    {
        return 'Create an OVHcloud API application, then delegate the smallest read-only rights: GET on /me for the connection test, and GET on the service endpoints the inventory integration documents. Lafiel never calls a mutating OVH endpoint — no create, renew, scale, or delete.';
    }

    public static function helpUrl(): string
    {
        return 'https://help.ovhcloud.com/csm/de-api-api-rights-delegation?id=kb_article_view&sysparm_article=KB0068603';
    }

    public static function summary(array $payload): string
    {
        return (string) ($payload['endpoint'] ?? '—');
    }

    /**
     * @return list<CredentialField>
     */
    public static function fields(): array
    {
        return [
            new CredentialField('endpoint', 'Endpoint', CredentialField::TYPE_SELECT, options: self::ENDPOINTS),
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
}
