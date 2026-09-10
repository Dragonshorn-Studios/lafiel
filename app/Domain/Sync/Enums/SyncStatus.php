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
}
