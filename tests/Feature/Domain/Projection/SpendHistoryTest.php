<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\SpendHistory;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function chargeActiveFrom(string $date, int $minor = 10000): CostItem
{
    return CostItem::factory()->create([
        'amount_minor' => $minor,
        'currency' => 'PLN',
        'valid_from' => $date,
    ]);
}

it('projects the last six months with the current month as an estimate', function () {
    // Live since April: every month in the window carries 100.00 PLN.
    chargeActiveFrom('2026-04-01');

    $series = app(SpendHistory::class)->monthly(new CarbonImmutable('2026-09-10 12:00:00'));

    expect($series)->toHaveCount(6)
        ->and(array_column($series, 'label'))->toBe([
            'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026',
        ])
        ->and(array_column($series, 'minor'))->toBe([10000, 10000, 10000, 10000, 10000, 10000])
        ->and(array_column($series, 'estimated'))->toBe([false, false, false, false, false, true])
        ->and($series[0]['currency'])->toBe('PLN');
});

it('reflects a charge only from the month it started', function () {
    chargeActiveFrom('2026-04-01');
    chargeActiveFrom('2026-08-15', 2400);

    $series = app(SpendHistory::class)->monthly(new CarbonImmutable('2026-09-10 12:00:00'));

    expect(array_column($series, 'minor'))->toBe([10000, 10000, 10000, 10000, 12400, 12400]);
});

it('reports zero for months without charges', function () {
    chargeActiveFrom('2026-09-01');

    $series = app(SpendHistory::class)->monthly(new CarbonImmutable('2026-09-10 12:00:00'));

    expect(array_column($series, 'minor'))->toBe([0, 0, 0, 0, 0, 10000]);
});
