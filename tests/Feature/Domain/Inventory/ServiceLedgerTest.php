<?php

namespace Tests\Feature\Domain\Inventory;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use App\Domain\Inventory\ServiceLedger;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function manualServiceWithCharge(array $chargeOverrides = [], array $serviceOverrides = []): Service
{
    $service = Service::factory()->create($serviceOverrides);

    CostItem::factory()->create([
        'amount_minor' => 5000,
        'currency' => 'PLN',
        'observed_at' => now(),
        ...$chargeOverrides,
    ])->services()->attach($service->id);

    return $service;
}

it('rolls a manual service up as manual, priced, and editable', function () {
    $service = manualServiceWithCharge();

    $row = app(ServiceLedger::class)->rows(new CarbonImmutable('2026-09-10 12:00:00'))[0];

    expect($row->provider)->toBe('Manual')
        ->and($row->chargeCount)->toBe(1)
        ->and($row->billing)->toBe('monthly')
        ->and($row->sourceAmount?->majorAmount())->toBe('50.00')
        ->and($row->monthlyMinor)->toBe(5000)
        ->and($row->monthlyCurrency)->toBe('PLN')
        ->and($row->freshness())->toBe('manual')
        ->and($row->package)->toBeFalse()
        ->and($row->manualChargeId)->not->toBeNull();
});

it('labels provider services and keeps synced freshness', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id]);

    $service = Service::factory()->discovered($account)->create();

    CostItem::factory()->subscriptionQuote()->create([
        'amount_minor' => 700,
        'currency' => 'EUR',
        'observed_at' => now(),
    ])->services()->attach($service->id);

    $row = app(ServiceLedger::class)->rows(new CarbonImmutable('2026-09-10 12:00:00'))[0];

    expect($row->provider)->toBe('OVHcloud')
        ->and($row->freshness())->toBe('synced')
        ->and($row->monthlyMinor)->toBe(700)
        ->and($row->monthlyCurrency)->toBe('EUR')
        ->and($row->charges[0]['evidence']->value)->toBe('quote')
        ->and($row->manualChargeId)->toBeNull();
});

it('flags provider evidence older than the freshness window as stale', function () {
    $account = ProviderAccount::factory()->create();
    $service = Service::factory()->discovered($account)->create();

    CostItem::factory()->subscriptionQuote()->create([
        'amount_minor' => 700,
        'currency' => 'EUR',
        'observed_at' => now()->subDays(30),
    ])->services()->attach($service->id);

    $row = app(ServiceLedger::class)->rows(new CarbonImmutable('2026-09-10 12:00:00'))[0];

    expect($row->staleCount)->toBe(1)
        ->and($row->freshness())->toBe('stale');
});

it('keeps unknown amounts out of the equivalent and counts them', function () {
    $service = Service::factory()->create();

    CostItem::factory()->unknownAmount()->create()->services()->attach($service->id);

    $row = app(ServiceLedger::class)->rows(new CarbonImmutable('2026-09-10 12:00:00'))[0];

    expect($row->monthlyMinor)->toBeNull()
        ->and($row->monthlyCurrency)->toBeNull()
        ->and($row->unknownCount)->toBe(1);
});

it('sums multiple charges on one service into a single equivalent', function () {
    $service = Service::factory()->create();

    CostItem::factory()->create(['amount_minor' => 2000, 'observed_at' => now()])->services()->attach($service->id);
    CostItem::factory()->create(['amount_minor' => 3000, 'observed_at' => now()])->services()->attach($service->id);

    $row = app(ServiceLedger::class)->rows(new CarbonImmutable('2026-09-10 12:00:00'))[0];

    expect($row->chargeCount)->toBe(2)
        ->and($row->monthlyMinor)->toBe(5000)
        // Two manual charges: editing either from the table is ambiguous.
        ->and($row->manualChargeId)->toBeNull();
});

it('marks services covered by a package charge', function () {
    $serviceA = Service::factory()->create();
    $serviceB = Service::factory()->create();

    $package = CostItem::factory()->create(['amount_minor' => 1000, 'observed_at' => now()]);
    $package->services()->attach([$serviceA->id, $serviceB->id]);

    $rows = collect(app(ServiceLedger::class)->rows(new CarbonImmutable('2026-09-10 12:00:00')))
        ->keyBy(fn ($row) => $row->service->id);

    expect($rows[$serviceA->id]->package)->toBeTrue()
        ->and($rows[$serviceB->id]->package)->toBeTrue()
        // The package equivalent appears per service row; the projection
        // still counts the charge exactly once.
        ->and($rows[$serviceA->id]->monthlyMinor)->toBe(1000);
});

it('renders services without any open charge', function () {
    Service::factory()->discovered()->create();

    $row = app(ServiceLedger::class)->rows(new CarbonImmutable('2026-09-10 12:00:00'))[0];

    expect($row->chargeCount)->toBe(0)
        ->and($row->billing)->toBeNull()
        ->and($row->monthlyMinor)->toBeNull()
        ->and($row->freshness())->toBe('synced');
});
