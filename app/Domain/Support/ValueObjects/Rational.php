<?php

namespace App\Domain\Support\ValueObjects;

use InvalidArgumentException;

/**
 * Exact rational number with integer numerator and positive denominator.
 * Sums of period-converted amounts stay exact here; rounding happens
 * once, half-even, when a total is presented.
 */
final readonly class Rational
{
    public readonly int $numerator;

    public readonly int $denominator;

    public function __construct(int $numerator, int $denominator = 1)
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Rational denominator cannot be zero.');
        }

        if ($denominator < 0) {
            $numerator *= -1;
            $denominator *= -1;
        }

        $this->numerator = $numerator;
        $this->denominator = $denominator;
    }

    public function add(self $other): self
    {
        $numerator = $this->numerator * $other->denominator + $other->numerator * $this->denominator;
        $denominator = $this->denominator * $other->denominator;

        return new self($numerator, $denominator)->reduced();
    }

    public function multiply(int $factor): self
    {
        return new self($this->numerator * $factor, $this->denominator)->reduced();
    }

    /**
     * Exact division by an integer factor, keeping an exact fraction
     * when the division is not even.
     */
    public function divide(int $divisor): self
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        return new self($this->numerator, $this->denominator * $divisor)->reduced();
    }

    /**
     * Round to the nearest integer, ties to even. Deterministic and
     * symmetric: 2.5 rounds to 2, 3.5 rounds to 4, -2.5 rounds to -2.
     */
    public function roundHalfEven(): int
    {
        $quotient = intdiv($this->numerator, $this->denominator);
        $remainder = $this->numerator - $quotient * $this->denominator;

        $twiceRemainder = abs($remainder) * 2;
        $sign = $this->numerator < 0 ? -1 : 1;

        if ($twiceRemainder > $this->denominator) {
            return $quotient + $sign;
        }

        if ($twiceRemainder < $this->denominator) {
            return $quotient;
        }

        return $quotient % 2 === 0 ? $quotient : $quotient + $sign;
    }

    private function reduced(): self
    {
        $gcd = self::gcd($this->numerator, $this->denominator);

        if ($gcd <= 1) {
            return $this;
        }

        return new self(intdiv($this->numerator, $gcd), intdiv($this->denominator, $gcd));
    }

    private static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b > 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
