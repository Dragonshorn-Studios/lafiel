<?php

namespace App\Domain\Providers\Dtos;

use App\Domain\Providers\Enums\ConnectionStatus;

/**
 * Result of one provider connection test. Carries no credential
 * material: the warning is already a sanitized provider message, safe
 * for toasts, logs, and summaries.
 */
final readonly class ConnectionCheck
{
    private function __construct(
        public ConnectionStatus $status,
        public ?string $warning = null,
    ) {}

    public static function connected(): self
    {
        return new self(ConnectionStatus::Connected);
    }

    public static function rejected(string $warning): self
    {
        return new self(ConnectionStatus::Rejected, $warning);
    }

    public static function unreachable(string $warning): self
    {
        return new self(ConnectionStatus::Unreachable, $warning);
    }
}
