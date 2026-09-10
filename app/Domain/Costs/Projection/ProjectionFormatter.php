<?php

namespace App\Domain\Costs\Projection;

use App\Domain\Support\ValueObjects\Money;

/**
 * Renders projection results in the display forms allowed by
 * Architecture v2:
 *
 *   187.42 PLN/mo
 *   ~187.42 PLN/mo
 *   187.42 PLN/mo + 2 unknown
 *   1240.00 PLN/yr
 */
class ProjectionFormatter
{
    public function monthly(ProjectionResult $result, ?string $currency = null): string
    {
        return $this->format($result, $currency, suffix: '/mo');
    }

    public function annual(ProjectionResult $result, ?string $currency = null): string
    {
        return $this->format($result, $currency, suffix: '/yr');
    }

    private function format(ProjectionResult $result, ?string $currency, string $suffix): string
    {
        $currency ??= (string) config('costs.display_currency', 'PLN');

        $total = $result->forCurrency($currency);

        $label = $total === null
            ? Money::ofMinor(0, $currency)->majorAmount()." $currency$suffix"
            : Money::ofMinor(
                $suffix === '/mo' ? $total->monthlyMinor : $total->annualMinor,
                $currency,
            )->majorAmount()." $currency$suffix";

        if ($result->estimateCount > 0) {
            $label = '~'.$label;
        }

        if ($result->unknownCount > 0) {
            $label .= " + {$result->unknownCount} unknown";
        }

        return $label;
    }
}
