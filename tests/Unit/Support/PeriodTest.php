<?php

use App\Domain\Costs\Enums\Period;
use App\Domain\Support\ValueObjects\Money;

test('monthly amounts convert unchanged', function () {
    $monthly = Period::Monthly->monthlyEquivalent(Money::ofString('187.42', 'PLN'));

    expect($monthly->roundHalfEven())->toEqual(18742);
});

test('quarterly amounts divide by exactly three', function () {
    $monthly = Period::Quarterly->monthlyEquivalent(Money::ofString('187.42', 'PLN'));

    // 187.42 / 3 = 62.4733...; rounding happens only at presentation.
    expect($monthly->roundHalfEven())->toEqual(6247);

    $annual = Period::Quarterly->annualEquivalent(Money::ofString('187.42', 'PLN'));

    expect($annual->roundHalfEven())->toEqual(74968);
});

test('annual amounts divide by exactly twelve', function () {
    $monthly = Period::Annual->monthlyEquivalent(Money::ofString('1200.00', 'PLN'));

    expect($monthly->roundHalfEven())->toEqual(10000);

    $annual = Period::Annual->annualEquivalent(Money::ofString('1200.00', 'PLN'));

    expect($annual->roundHalfEven())->toEqual(120000);
});

test('one-time and unknown periods have no recurring equivalents', function () {
    $amount = Money::ofString('500.00', 'PLN');

    expect(Period::OneTime->monthlyEquivalent($amount))->toBeNull();
    expect(Period::OneTime->annualEquivalent($amount))->toBeNull();
    expect(Period::Unknown->monthlyEquivalent($amount))->toBeNull();
    expect(Period::Unknown->annualEquivalent($amount))->toBeNull();
});

test('a quarterly charge summed three times reproduces the source amount', function () {
    $amount = Money::ofString('99.99', 'PLN');
    $monthly = Period::Quarterly->monthlyEquivalent($amount);

    $threeMonths = $monthly->add($monthly)->add($monthly);

    // 3 x 33.33 rounds to 99.99: the remainder never leaks into the sum.
    expect($threeMonths->roundHalfEven())->toEqual(9999);
});
