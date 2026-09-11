<?php

namespace App\Domain\Providers\Dtos;

/**
 * Result of a credential validation. Carries no credential material —
 * only whether the provider accepted them and a sanitized reason when
 * it did not.
 */
final readonly class CredentialCheck
{
    public function __construct(
        public bool $valid,
        public ?string $warning = null,
    ) {
        if (! $valid && ($warning === null || $warning === '')) {
            throw new \InvalidArgumentException('An invalid credential check must carry a warning.');
        }
    }

    public static function valid(): self
    {
        return new self(true);
    }

    public static function invalid(string $warning): self
    {
        return new self(false, $warning);
    }
}
