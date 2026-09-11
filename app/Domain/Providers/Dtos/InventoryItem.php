<?php

namespace App\Domain\Providers\Dtos;

/**
 * One discovered resource. The provider's external identity and type
 * are preserved separately from the canonical category.
 */
final readonly class InventoryItem
{
    public function __construct(
        public string $externalId,
        public string $category,
        public string $name,
        public ?string $providerType = null,
        public ?string $url = null,
    ) {}
}
