<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Costs\UpcomingRenewals;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function renewalCharge(array $overrides = []): CostItem
{
    return CostItem::factory()->create($overrides);
}

function renewalFor(CostItem $item, string $date): Renewal
{
    return Renewal::query()->create([
        'cost_item_id' => $item->id,
        'renews_at' => $date,
        'auto_renew' => false,
    ]);
}

it('returns every open renewal due within the window, oldest first', function () {
    $inWindow = renewalCharge(['amount_minor' => 4900]);
    $farAway = renewalCharge(['amount_minor' => 2400]);
    $ended = renewalCharge(['amount_minor' => 1600, 'valid_to' => '2026-09-01']);

    renewalFor($inWindow, '2026-09-20');
    renewalFor($farAway, '2026-10-20');
    renewalFor($ended, '2026-09-12');

    $window = app(UpcomingRenewals::class)->within(new CarbonImmutable('2026-09-10 12:00:00'));

    expect(count($window->rows))->toBe(1)
        ->and($window->rows[0]['amount']?->majorAmount())->toBe('49.00')
        ->and($window->rows[0]['renews_at']->toDateString())->toBe('2026-09-20')
        ->and($window->totalFor('PLN'))->toBe(4900)
        ->and($window->unknownCount)->toBe(0);
});

it('names the service and its provider when coverage exists', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $service = Service::factory()->discovered($account)->create();

    $item = renewalCharge();
    $item->services()->attach($service->id);
    renewalFor($item, '2026-09-15');

    $window = app(UpcomingRenewals::class)->within(new CarbonImmutable('2026-09-10 12:00:00'));

    expect($window->rows[0]['name'])->toBe($service->name)
        ->and($window->rows[0]['provider'])->toBe('OVHcloud');
});

it('presents manual charges without coverage as manual', function () {
    $item = renewalCharge();
    renewalFor($item, '2026-09-15');

    $window = app(UpcomingRenewals::class)->within(new CarbonImmutable('2026-09-10 12:00:00'));

    expect($window->rows[0]['provider'])->toBe(__('Manual'))
        ->and($window->rows[0]['name'])->toBe(__('Unnamed charge'));
});

it('keeps unknown amounts visible but outside the totals', function () {
    $unknown = CostItem::factory()->unknownAmount()->create();

    renewalFor($unknown, '2026-09-15');

    $window = app(UpcomingRenewals::class)->within(new CarbonImmutable('2026-09-10 12:00:00'));

    expect(count($window->rows))->toBe(1)
        ->and($window->rows[0]['amount'])->toBeNull()
        ->and($window->totalFor('PLN'))->toBeNull()
        ->and($window->unknownCount)->toBe(1);
});

it('sums per currency without converting', function () {
    renewalFor(renewalCharge(['amount_minor' => 4900, 'currency' => 'PLN']), '2026-09-15');
    renewalFor(renewalCharge(['amount_minor' => 700, 'currency' => 'EUR']), '2026-09-18');

    $window = app(UpcomingRenewals::class)->within(new CarbonImmutable('2026-09-10 12:00:00'));

    expect($window->totalFor('PLN'))->toBe(4900)
        ->and($window->totalFor('EUR'))->toBe(700)
        ->and($window->totalsMinor)->toBe(['PLN' => 4900, 'EUR' => 700]);
});
