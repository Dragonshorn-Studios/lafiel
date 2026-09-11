<?php

namespace App\Domain\Providers\Enums;

/**
 * Capability completeness of one provider batch: a complete batch saw
 * everything, a partial batch saw some of it, an unsupported capability
 * was never fetched, and a failed batch produced nothing usable.
 */
enum BatchCompleteness: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Unsupported = 'unsupported';
    case Failed = 'failed';

    public function producedUsableData(): bool
    {
        return in_array($this, [self::Complete, self::Partial], true);
    }
}
