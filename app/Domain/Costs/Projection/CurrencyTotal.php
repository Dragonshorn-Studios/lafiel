<?php

namespace App\Domain\Costs\Projection;

use App\Domain\Support\ValueObjects\Rational;

/**
 * Rounded, presented totals for one currency. Monthly and annual
 * equivalents are summed exactly and rounded once, half-even.
 */
final readonly class CurrencyTotal
{
    public function __construct(
        public string $currency,
        public int $monthlyMinor,
        public int $annualMinor,
        public int $oneTimeMinor,
        /**
         * @var list<ChargeLine>
         */
        public array $lines,
    ) {}

    public static function empty(string $currency): self
    {
        return new self($currency, 0, 0, 0, []);
    }

    /**
     * @param  array{monthly: Rational, annual: Rational, oneTime: int, lines: list<ChargeLine>}  $accumulator
     */
    public static function fromAccumulator(string $currency, array $accumulator): self
    {
        return new self(
            currency: $currency,
            monthlyMinor: $accumulator['monthly']->roundHalfEven(),
            annualMinor: $accumulator['annual']->roundHalfEven(),
            oneTimeMinor: $accumulator['oneTime'],
            lines: $accumulator['lines'],
        );
    }
}
