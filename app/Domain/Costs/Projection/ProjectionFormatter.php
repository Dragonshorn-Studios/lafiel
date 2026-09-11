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
 *
 * Amounts held in currencies other than the display currency are never
 * converted; otherCurrencies() renders them as their own labels so
 * known spend cannot silently disappear from an Overview.
 */
class ProjectionFormatter
{
    public function monthly(ProjectionResult $result, ?string $currency = null): string
    {
        return $this->format($result, $currency, monthly: true);
    }

    public function annual(ProjectionResult $result, ?string $currency = null): string
    {
        return $this->format($result, $currency, monthly: false);
    }

    /**
     * Monthly labels for every currency other than the display currency,
     * in stable currency order.
     *
     * @return list<string>
     */
    public function otherCurrencies(ProjectionResult $result, ?string $display = null): array
    {
        $display ??= $this->displayCurrency();

        $labels = [];

        foreach ($result->currencies() as $total) {
            if ($total->currency === $display) {
                continue;
            }

            $labels[] = Money::ofMinor($total->monthlyMinor, $total->currency)->majorAmount()
                .' '.$total->currency.'/mo';
        }

        return $labels;
    }

    private function format(ProjectionResult $result, ?string $currency, bool $monthly): string
    {
        $currency ??= $this->displayCurrency();

        $total = $result->forCurrency($currency);

        $minor = $total === null
            ? 0
            : ($monthly ? $total->monthlyMinor : $total->annualMinor);

        $suffix = $monthly ? '/mo' : '/yr';

        $label = Money::ofMinor($minor, $currency)->majorAmount()." $currency$suffix";

        if ($result->estimateCount > 0) {
            $label = '~'.$label;
        }

        if ($result->unknownCount > 0) {
            $label .= " + {$result->unknownCount} unknown";
        }

        return $label;
    }

    private function displayCurrency(): string
    {
        return (string) config('costs.display_currency', 'PLN');
    }
}
