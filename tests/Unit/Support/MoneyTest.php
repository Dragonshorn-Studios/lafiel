<?php

use App\Domain\Support\ValueObjects\Money;
use App\Domain\Support\ValueObjects\Rational;

test('money is built from integer minor units and an ISO-4217 code', function () {
    $money = Money::ofMinor(18742, 'pln');

    expect($money->amountMinor)->toEqual(18742);
    expect($money->currency)->toEqual('PLN');
});

test('money parses decimal strings without touching a float', function () {
    expect(Money::ofString('187.42', 'PLN')->amountMinor)->toEqual(18742);
    expect(Money::ofString('0.05', 'PLN')->amountMinor)->toEqual(5);
    expect(Money::ofString('0.5', 'PLN')->amountMinor)->toEqual(50);
    expect(Money::ofString('187', 'PLN')->amountMinor)->toEqual(18700);
    expect(Money::ofString('-0.05', 'PLN')->amountMinor)->toEqual(-5);
    expect(Money::ofString(' 187.40 ', 'PLN')->amountMinor)->toEqual(18740);
});

test('money rejects malformed amounts and currencies', function () {
    Money::ofString('187.421', 'PLN');
})->throws(InvalidArgumentException::class);

test('money rejects malformed currencies', function () {
    Money::ofMinor(100, 'dollars');
})->throws(InvalidArgumentException::class);

test('money arithmetic stays within one currency', function () {
    $sum = Money::ofMinor(10000, 'PLN')->add(Money::ofMinor(8742, 'PLN'));

    expect($sum->amountMinor)->toEqual(18742);

    Money::ofMinor(1, 'PLN')->add(Money::ofMinor(1, 'EUR'));
})->throws(InvalidArgumentException::class);

test('money renders the major amount deterministically', function () {
    expect(Money::ofMinor(18742, 'PLN')->majorAmount())->toEqual('187.42');
    expect(Money::ofMinor(5, 'PLN')->majorAmount())->toEqual('0.05');
    expect(Money::ofMinor(-5, 'PLN')->majorAmount())->toEqual('-0.05');
});

test('rational addition is exact across different denominators', function () {
    $third = Money::ofMinor(18742, 'PLN')->toRational()->divide(3);
    $sum = $third->add($third)->add($third);

    // 3 x 187.42/3 is exactly 187.42 again; no precision was lost.
    expect($sum->roundHalfEven())->toEqual(18742);
});

test('half-even rounding is deterministic and symmetric', function () {
    expect((new Rational(5, 2))->roundHalfEven())->toEqual(2);
    expect((new Rational(7, 2))->roundHalfEven())->toEqual(4);
    expect((new Rational(-5, 2))->roundHalfEven())->toEqual(-2);
    expect((new Rational(1, 3))->roundHalfEven())->toEqual(0);
    expect((new Rational(2, 3))->roundHalfEven())->toEqual(1);
    expect((new Rational(18742, 1))->roundHalfEven())->toEqual(18742);
});
