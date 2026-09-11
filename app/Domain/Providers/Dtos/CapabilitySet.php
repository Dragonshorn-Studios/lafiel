<?php

namespace App\Domain\Providers\Dtos;

use App\Domain\Providers\Enums\ProviderCapability;

/**
 * The capabilities an adapter explicitly supports. Absence is explicit:
 * a capability outside the set is never fetched and never faked with an
 * empty implementation.
 */
final readonly class CapabilitySet
{
    /** @param list<ProviderCapability> $capabilities */
    public function __construct(
        public array $capabilities,
    ) {}

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return list<ProviderCapability>
     */
    public function costCapabilities(): array
    {
        return array_values(array_filter(
            ProviderCapability::costCapabilities(),
            fn (ProviderCapability $capability): bool => $this->supports($capability),
        ));
    }
}
