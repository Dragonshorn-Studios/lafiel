<?php

namespace App\Domain\Costs\Enums;

enum TaxBasis: string
{
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
    case Unknown = 'unknown';
}
