<?php

namespace App\Domain\Sync\Enums;

enum SyncStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('queued'),
            self::Running => __('running'),
            self::Succeeded => __('succeeded'),
            self::Partial => __('partial'),
            self::Failed => __('failed'),
            self::Cancelled => __('cancelled'),
        };
    }
}
