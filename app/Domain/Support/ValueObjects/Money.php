<?php

namespace App\Domain\Support\ValueObjects;

use InvalidArgumentException;

/**
 * Money as integer minor units plus an ISO-4217 currency code.
 * Binary floats never touch an amount; decimal strings are parsed
 * digit by digit.
 */
final readonly class Money
{
    /**
     * Amounts are whole minor units; the working range ends where
     * minor-unit arithmetic could overflow a 64-bit integer.
     */
    private const MAX_MINOR = PHP_INT_MAX / 100;

    public readonly int $amountMinor;

    public readonly string $currency;

    public function __construct(int $amountMinor, string $currency)
    {
        $currency = mb_strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO-4217 code, got '{$currency}'.");
        }

        $this->amountMinor = $amountMinor;
        $this->currency = $currency;
    }

    public static function ofMinor(int $amountMinor, string $currency): self
    {
        return new self($amountMinor, $currency);
    }

    /**
     * Build from a decimal string such as "187.42" or "-0.05".
     * At most two decimal places are accepted; input is never
     * converted through a float and values that could overflow the
     * integer minor-unit range are rejected.
     */
    public static function ofString(string $amount, string $currency): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $matches) !== 1) {
            throw new InvalidArgumentException("Amount must be a decimal string with at most two fractional digits, got '{$amount}'.");
        }

        $whole = (int) $matches[2];

        if ($whole > self::MAX_MINOR) {
            throw new InvalidArgumentException("Amount '{$amount}' exceeds the supported range.");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $fraction = $matches[3] ?? '';

        return new self($sign * ($whole * 100 + (int) str_pad($fraction, 2, '0')), $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amountMinor * $factor, $this->currency);
    }

    public function toRational(): Rational
    {
        return new Rational($this->amountMinor);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor
            && $this->currency === $other->currency;
    }

    /**
     * The major-unit amount as a plain string, e.g. "187.42".
     */
    public function majorAmount(): string
    {
        $sign = $this->amountMinor < 0 ? '-' : '';
        $abs = abs($this->amountMinor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot operate on {$this->currency} and {$other->currency} amounts.");
        }
    }
}
