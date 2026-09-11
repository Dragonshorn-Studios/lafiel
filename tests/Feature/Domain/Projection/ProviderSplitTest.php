<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function chargeWithService(CostItem $item, ?Service $service = null): CostItem
{
    if ($service !== null) {
        $item->services()->attach($service->id);
    }

    return $item;
}

it('groups monthly spend by provider and manual charges separately', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $service = Service::factory()->discovered($account)->create();

    chargeWithService(CostItem::factory()->create(['amount_minor' => 13742]), $service);
    chargeWithService(CostItem::factory()->create(['amount_minor' => 5000]));

    $result = app(CostProjector::class)->project(new CarbonImmutable('2026-09-10 12:00:00'));

    expect($result->providerSplit('PLN'))->toBe([
        'OVHcloud' => 13742,
        'Manual' => 5000,
    ])
        ->and($result->pricedCount())->toBe(2)
        ->and($result->unknownCount)->toBe(0);
});

it('adds up to exactly the rounded-once monthly total', function () {
    // Three charges whose thirds do not divide evenly in minor units.
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $service = Service::factory()->discovered($account)->create();

    chargeWithService(CostItem::factory()->create(['amount_minor' => 9999, 'period' => 'quarterly']), $service);
    chargeWithService(CostItem::factory()->create(['amount_minor' => 100, 'period' => 'quarterly']), $service);
    chargeWithService(CostItem::factory()->create(['amount_minor' => 1999]));

    $result = app(CostProjector::class)->project(new CarbonImmutable('2026-09-10 12:00:00'));

    $total = $result->forCurrency('PLN')->monthlyMinor;
    $sumOfSplit = array_sum($result->providerSplit('PLN'));

    expect($sumOfSplit)->toBe($total)
        // 9999/3 + 100/3 + 1999 = 3333 + 33 + 1999 = 5365.
        ->and($total)->toBe(5365);
});

it('returns an empty split and zero priced count when nothing is priced', function () {
    CostItem::factory()->unknownAmount()->create();

    $result = app(CostProjector::class)->project(new CarbonImmutable('2026-09-10 12:00:00'));

    expect($result->providerSplit('PLN'))->toBe([])
        ->and($result->pricedCount())->toBe(0)
        ->and($result->unknownCount)->toBe(1);
});

it('carries the provider label through the breakdown', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'Hetzner']);
    $service = Service::factory()->discovered($account)->create();

    chargeWithService(CostItem::factory()->create(), $service);

    $breakdown = app(CostProjector::class)->project(new CarbonImmutable('2026-09-10 12:00:00'))->breakdown();

    expect($breakdown['lines'][0]['provider'])->toBe('Hetzner');
});
