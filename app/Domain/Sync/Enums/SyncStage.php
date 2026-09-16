<?php

namespace App\Domain\Sync\Enums;

/**
 * The processing phase a sync run has reached, written by the
 * orchestrator as it moves through the algorithm. Terminal runs keep
 * their last stage, so a failure names the phase it died in.
 */
enum SyncStage: string
{
    case Credentials = 'credentials';
    case Inventory = 'inventory';
    case Costs = 'costs';
    case Persisting = 'persisting';

    public function label(): string
    {
        return match ($this) {
            self::Credentials => __('validating credentials'),
            self::Inventory => __('fetching inventory'),
            self::Costs => __('fetching cost facts'),
            self::Persisting => __('persisting results'),
        };
    }
}
