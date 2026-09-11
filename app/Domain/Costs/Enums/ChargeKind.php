<?php

namespace App\Domain\Costs\Enums;

enum ChargeKind: string
{
    case RecurringFixed = 'recurring_fixed';
    case Usage = 'usage';
    case OneTime = 'one_time';
}
