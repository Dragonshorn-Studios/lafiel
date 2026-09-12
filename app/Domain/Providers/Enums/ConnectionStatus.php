<?php

namespace App\Domain\Providers\Enums;

/**
 * Outcome of a provider connection test. Rejected is a distinct,
 * permanent state: the credentials were refused and must be replaced by
 * a human, not retried.
 */
enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
}
