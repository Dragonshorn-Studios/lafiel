<?php

namespace App\Domain\Providers\Ovh;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shape of the OVH credential payload stored in
 * `provider_credentials.payload` (`schema_version` 1): the three
 * OVHcloud API credentials plus the endpoint name. Only read access is
 * ever requested for these keys; the whitelist keeps a typo from
 * pointing the account at a different OVH platform.
 */
final class OvhCredentialSchema
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
