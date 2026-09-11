<?php

namespace App\Domain\Costs\Enums;

/**
 * How a cost item relates to services. Ambiguity is an allocation
 * state, not a synonym for an unknown amount.
 */
enum AllocationState: string
{
    case Direct = 'direct';
    case SharedUnallocated = 'shared_unallocated';
    case Allocated = 'allocated';
}
