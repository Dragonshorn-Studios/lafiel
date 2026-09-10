<?php

namespace App\Domain\Inventory\Enums;

/**
 * Canonical service lifecycle. A service becomes inactive only after
 * the configured number of successful complete inventories omit it;
 * records are never hard-deleted.
 */
enum ServiceLifecycle: string
{
    case Active = 'active';
    case Missing = 'missing';
    case Inactive = 'inactive';
}
