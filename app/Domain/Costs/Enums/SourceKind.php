<?php

namespace App\Domain\Costs\Enums;

/**
 * Where a cost fact comes from. One of the independent canonical
 * dimensions; never collapsed with evidence state or allocation.
 */
enum SourceKind: string
{
    case Subscription = 'subscription';
    case RenewalQuote = 'renewal_quote';
    case Usage = 'usage';
    case Invoice = 'invoice';
    case Manual = 'manual';
}
