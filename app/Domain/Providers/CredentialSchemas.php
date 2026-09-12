<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Exceptions\UnsupportedProviderException;
use InvalidArgumentException;

/**
 * Registry of credential schemas by provider key, the credentials-side
 * counterpart of the AdapterRegistry. The connect/update actions and
 * the settings form resolve the schema here; a provider is only
 * connectable when both an adapter and a schema are registered — the
 * two registries are populated in lockstep at boot, and nothing
 * enforces that beyond this comment.
 *
 * @internal string keys on purpose: a provider is a plain `provider_key`
 *            column, not an enum (see AdapterRegistry).
 */
final class CredentialSchemas
{
    /** @var array<string, class-string<CredentialSchema>> */
    private array $schemas = [];

    /**
     * @param  class-string<CredentialSchema>  $schema
     */
    public function register(string $providerKey, string $schema): void
    {
        if (isset($this->schemas[$providerKey])) {
            throw new InvalidArgumentException("Credential schema [{$providerKey}] is already registered.");
        }

        $this->schemas[$providerKey] = $schema;
    }

    /**
     * @return class-string<CredentialSchema>
     *
     * @throws UnsupportedProviderException
     */
    public function for(string $providerKey): string
    {
        return $this->schemas[$providerKey]
            ?? throw new UnsupportedProviderException("No credential schema is registered for [{$providerKey}].");
    }

    /**
     * Connectable providers for the form's select, label-ordered.
     *
     * @return array<string, string> provider key => label
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->schemas as $providerKey => $schema) {
            $options[$providerKey] = $schema::label();
        }

        asort($options);

        return $options;
    }
}
