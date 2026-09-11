<?php

namespace App\Domain\Costs\Projection;

use App\Domain\Support\ValueObjects\Rational;

/**
 * Rounded, presented totals for one currency. Monthly and annual
 * equivalents are summed exactly and rounded once, half-even. The only
 * way to build one is through the accumulator factory, so the
 * rounded-once invariant cannot be bypassed by callers.
 */
final readonly class CurrencyTotal
{
    /**
     * @var list<ChargeLine>
     */
    private array $lines;

    /**
     * @param  list<ChargeLine>  $lines
     */
    private function __construct(
        public string $currency,
        public int $monthlyMinor,
        public int $annualMinor,
        public int $oneTimeMinor,
        array $lines,
    ) {
        $this->lines = $lines;
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

    /**
     * @return list<ChargeLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
