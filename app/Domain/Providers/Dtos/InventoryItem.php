<?php

namespace App\Domain\Providers\Dtos;

/**
 * One discovered resource. The provider's external identity and type
 * are preserved separately from the canonical category. Metadata is
 * the provider's own lifecycle detail (offer, creation, expiration),
 * stored verbatim next to the canonical record.
 */
final readonly class InventoryItem
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $externalId,
        public string $category,
        public string $name,
        public ?string $providerType = null,
        public ?string $url = null,
        public ?array $metadata = null,
    ) {}
}
