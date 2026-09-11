<?php

namespace App\Domain\Providers;

use App\Domain\Providers\Contracts\ProviderAdapter;
use App\Domain\Providers\Exceptions\UnsupportedProviderException;

/**
 * Registry of provider adapters by provider key. Registered as a
 * singleton; adapters register themselves at boot (the OVH adapter
 * arrives with its own issue). The registry resolves adapters only —
 * it never persists or fetches on its own.
 */
final class AdapterRegistry
{
    /** @var array<string, ProviderAdapter> */
    private array $adapters = [];

    public function register(string $providerKey, ProviderAdapter $adapter): void
    {
        $this->adapters[$providerKey] = $adapter;
    }

    /**
     * @throws UnsupportedProviderException when no adapter is registered
     *                                      for the provider key
     */
    public function for(string $providerKey): ProviderAdapter
    {
        $adapter = $this->adapters[$providerKey] ?? null;

        if ($adapter === null) {
            throw new UnsupportedProviderException("No adapter is registered for provider [{$providerKey}].");
        }

        return $adapter;
    }

    /**
     * @return list<string>
     */
    public function registeredKeys(): array
    {
        return array_keys($this->adapters);
    }
}
