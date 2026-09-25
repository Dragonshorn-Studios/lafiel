<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Dtos\CredentialField;
use App\Domain\Providers\Dtos\CredentialHelpStep;
use Illuminate\Validation\ValidationException;

/**
 * Shape of one provider's stored credential payload. Schemas are
 * static contracts: the settings form renders `fields()`, the connect
 * and update actions validate through `rules()` and store `payload()`,
 * and the adapter's API builder re-checks `assertValid()` before any
 * network call. Only read access is ever requested for these keys —
 * credentials are created out-of-band by the user (docs/integrations.md).
 */
interface CredentialSchema
{
    public const SCHEMA_VERSION = 1;

    /**
     * Provider label shown in the UI.
     */
    public static function label(): string;

    /**
     * Least-privilege guidance for creating the credentials out-of-band.
     */
    public static function help(): string;

    /**
     * Optional documentation URL behind the help text.
     */
    public static function helpUrl(): ?string;

    /**
     * Ordered setup steps rendered under the help text — for providers
     * whose credentials take more than "create a token". An empty list
     * renders the help text alone.
     *
     * @return list<CredentialHelpStep>
     */
    public static function helpSteps(): array;

    /**
     * Non-secret one-line summary of a stored payload for the account
     * card (an endpoint name, "API token"). Never echo secret fields.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function summary(array $payload): string;

    /**
     * Validation rules for the settings form. Secret fields are always
     * entered fresh; stored values are never rendered back.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array;

    /**
     * Form field metadata, in render order. Names are the snake_case
     * payload keys; the view translates labels.
     *
     * @return list<CredentialField>
     */
    public static function fields(): array;

    /**
     * Normalized credential payload for storage.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function payload(array $validated): array;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public static function assertValid(array $payload): void;
}
