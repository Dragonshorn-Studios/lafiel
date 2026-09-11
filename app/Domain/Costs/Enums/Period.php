<?php

namespace App\Domain\Costs\Enums;

use App\Domain\Support\ValueObjects\Money;
use App\Domain\Support\ValueObjects\Rational;

/**
 * Recurrence of a charge. Monthly and annual equivalents are exact
 * rational conversions (quarterly divided by 3, annual divided by 12);
 * rounding happens once on the presented total.
 */
enum Period: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';
    case OneTime = 'one_time';
    case Unknown = 'unknown';

    /**
     * The exact monthly equivalent, or null for periods that have none.
     */
    public function monthlyEquivalent(Money $amount): ?Rational
    {
        $rational = $amount->toRational();

        return match ($this) {
            self::Monthly => $rational,
            self::Quarterly => $rational->divide(3),
            self::Annual => $rational->divide(12),
            self::OneTime, self::Unknown => null,
        };
    }

    /**
     * The exact annual equivalent, or null for periods that have none.
     */
    public function annualEquivalent(Money $amount): ?Rational
    {
        $rational = $amount->toRational();

        return match ($this) {
            self::Monthly => $rational->multiply(12),
            self::Quarterly => $rational->multiply(4),
            self::Annual => $rational,
            self::OneTime, self::Unknown => null,
        };
    }
}
