<?php

namespace App\Domain\Costs\Enums;

/**
 * Whether the amount of a cost item is known. Unknown amounts stay
 * outside the known sum and are counted explicitly.
 */
enum AmountState: string
{
    case Known = 'known';
    case Unknown = 'unknown';
}
