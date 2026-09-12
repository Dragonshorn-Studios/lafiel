<?php

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\AmountState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Costs\Projection\ProjectionFormatter;
use App\Domain\Costs\Projection\ProjectionResult;
use App\Domain\Inventory\Models\Service;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function projectOn(string $date): ProjectionResult
{
    return app(CostProjector::class)->project(new CarbonImmutable($date));
}

test('recurring items are summed per currency and rounded once', function () {
    // Three annual items of 10.00: the exact monthly sum is 30.00/12 = 2.50.
    // Rounding each item first would lose the remainder and report 2.49.
    foreach ([1, 2, 3] as $i) {
        CostItem::factory()->create([
            'period' => Period::Annual,
            'amount_minor' => 1000,
            'currency' => 'PLN',
            'identity_key' => "identity-$i",
            'logical_charge_key' => "charge-$i",
        ]);
    }

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(250);
    expect($result->forCurrency('PLN')->annualMinor)->toEqual(3000);
});

test('a package charge covering many services is counted once', function () {
    $package = CostItem::factory()->create(['amount_minor' => 5000, 'currency' => 'PLN']);
    $services = Service::factory()->count(3)->create();

    $package->services()->attach($services->pluck('id'));

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(5000);
    expect(count($result->forCurrency('PLN')->lines()))->toEqual(1);
});

test('invoice actual replaces a subscription quote instead of adding to it', function () {
    $charge = 'charge:vps-01:monthly';

    CostItem::factory()->subscriptionQuote()->create([
        'logical_charge_key' => $charge,
        'amount_minor' => 9000,
        'currency' => 'PLN',
    ]);

    CostItem::factory()->invoiceActual()->create([
        'logical_charge_key' => $charge,
        'amount_minor' => 8100,
        'currency' => 'PLN',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(8100);
    expect(count($result->forCurrency('PLN')->lines()))->toEqual(1);
});

test('a conscious manual override outranks an invoice actual', function () {
    $charge = 'charge:vps-02:monthly';

    CostItem::factory()->invoiceActual()->create([
        'logical_charge_key' => $charge,
        'amount_minor' => 8100,
        'currency' => 'PLN',
    ]);

    CostItem::factory()->manualOverride()->create([
        'logical_charge_key' => $charge,
        'amount_minor' => 7000,
        'currency' => 'PLN',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(7000);
});

test('plain manual evidence loses to stronger evidence', function () {
    $charge = 'charge:vps-03:monthly';

    CostItem::factory()->subscriptionQuote()->create([
        'logical_charge_key' => $charge,
        'amount_minor' => 9000,
        'currency' => 'PLN',
    ]);

    CostItem::factory()->create([
        'logical_charge_key' => $charge,
        'amount_minor' => 1000,
        'currency' => 'PLN',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(9000);
});

test('unknown amounts stay outside the known sum and are counted', function () {
    CostItem::factory()->create(['amount_minor' => 5000, 'currency' => 'PLN']);
    CostItem::factory()->unknownAmount()->create();

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(5000);
    expect($result->unknownCount)->toEqual(1);
});

test('one-time charges stay outside recurring spend and are counted', function () {
    CostItem::factory()->create(['amount_minor' => 5000, 'currency' => 'PLN']);
    CostItem::factory()->create([
        'charge_kind' => ChargeKind::OneTime,
        'period' => Period::OneTime,
        'amount_minor' => 120000,
        'currency' => 'PLN',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(5000);
    expect($result->forCurrency('PLN')->oneTimeMinor)->toEqual(120000);
    expect($result->oneTimeCount)->toEqual(1);
});

test('ended items leave the future projection but appear on past dates', function () {
    CostItem::factory()->ended('2026-09-01')->create([
        'amount_minor' => 5000,
        'currency' => 'PLN',
        'valid_from' => '2026-08-01',
    ]);

    expect(projectOn('2026-09-10')->forCurrency('PLN'))->toBeNull();
    expect(projectOn('2026-08-15')->forCurrency('PLN')->monthlyMinor)->toEqual(5000);
});

test('items that start in the future are not projected yet', function () {
    CostItem::factory()->create([
        'amount_minor' => 5000,
        'currency' => 'PLN',
        'valid_from' => '2026-10-01',
    ]);

    expect(projectOn('2026-09-10')->forCurrency('PLN'))->toBeNull();
    expect(projectOn('2026-10-15')->forCurrency('PLN')->monthlyMinor)->toEqual(5000);
});

test('estimate evidence is flagged with a tilde and counted', function () {
    CostItem::factory()->create([
        'evidence_state' => EvidenceState::Estimate,
        'amount_minor' => 5000,
        'currency' => 'PLN',
        'logical_charge_key' => 'charge-a',
    ]);
    CostItem::factory()->create([
        'amount_minor' => 2000,
        'currency' => 'PLN',
        'logical_charge_key' => 'charge-b',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->estimateCount)->toEqual(1);
    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(7000);

    $label = app(ProjectionFormatter::class)->monthly($result);

    expect($label)->toEqual('~70.00 PLN/mo');
});

test('synced evidence older than the freshness window counts as stale', function () {
    CostItem::factory()->subscriptionQuote()->create([
        'amount_minor' => 5000,
        'currency' => 'PLN',
        'logical_charge_key' => 'charge-a',
        'observed_at' => '2026-08-01 06:00:00',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->staleCount)->toEqual(1);
    // Stale evidence still contributes to the known sum.
    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(5000);
});

test('manual evidence never goes stale', function () {
    CostItem::factory()->create([
        'amount_minor' => 5000,
        'currency' => 'PLN',
        'logical_charge_key' => 'charge-a',
        'observed_at' => '2026-01-01 06:00:00',
    ]);

    expect(projectOn('2026-09-10')->staleCount)->toEqual(0);
});

test('shared unallocated charges are counted but still summed', function () {
    CostItem::factory()->sharedUnallocated()->create(['amount_minor' => 3000, 'currency' => 'PLN']);

    $result = projectOn('2026-09-10');

    expect($result->sharedUnallocatedCount)->toEqual(1);
    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(3000);
});

test('currencies are never silently converted', function () {
    CostItem::factory()->create(['amount_minor' => 5000, 'currency' => 'PLN']);
    CostItem::factory()->create(['amount_minor' => 999, 'currency' => 'EUR']);

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(5000);
    expect($result->forCurrency('EUR')->monthlyMinor)->toEqual(999);
});

test('quarterly charges contribute one third of their amount', function () {
    CostItem::factory()->create([
        'period' => Period::Quarterly,
        'amount_minor' => 30000,
        'currency' => 'PLN',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN')->monthlyMinor)->toEqual(10000);
});

test('known amounts with unknown period are counted as unknown', function () {
    CostItem::factory()->create([
        'period' => Period::Unknown,
        'amount_minor' => 5000,
        'currency' => 'PLN',
    ]);

    $result = projectOn('2026-09-10');

    expect($result->forCurrency('PLN'))->toBeNull();
    expect($result->unknownCount)->toEqual(1);
});

test('the formatter emits the allowed display forms', function () {
    $formatter = app(ProjectionFormatter::class);

    CostItem::factory()->create(['amount_minor' => 18742, 'currency' => 'PLN']);
    expect($formatter->monthly(projectOn('2026-09-10')))->toEqual('187.42 PLN/mo');

    CostItem::factory()->unknownAmount()->create(['logical_charge_key' => 'charge-b']);
    expect($formatter->monthly(projectOn('2026-09-10')))->toEqual('187.42 PLN/mo + 1 unknown');
});

test('the result carries the calculation version for snapshots', function () {
    expect(projectOn('2026-09-10')->calculationVersion)->toEqual('v1');
});

test('the annual display form renders the yearly equivalent', function () {
    CostItem::factory()->create(['amount_minor' => 18742, 'currency' => 'PLN']);

    expect(app(ProjectionFormatter::class)->annual(projectOn('2026-09-10')))
        ->toEqual('2249.04 PLN/yr');
});

test('non-display-currency spend is surfaced as its own label, never converted', function () {
    CostItem::factory()->create(['amount_minor' => 18742, 'currency' => 'PLN']);
    CostItem::factory()->create(['amount_minor' => 999, 'currency' => 'EUR']);

    $formatter = app(ProjectionFormatter::class);
    $result = projectOn('2026-09-10');

    expect($formatter->monthly($result))->toEqual('187.42 PLN/mo');
    expect($formatter->otherCurrencies($result))->toEqual(['9.99 EUR/mo']);
    expect($formatter->otherCurrencies($result, 'EUR'))->toEqual(['187.42 PLN/mo']);
});

test('the breakdown is snapshot ready', function () {
    CostItem::factory()->create([
        'amount_minor' => 18742,
        'currency' => 'PLN',
        'allocation_state' => AllocationState::Direct,
    ]);

    $breakdown = projectOn('2026-09-10')->breakdown();

    expect($breakdown['calculation_version'])->toEqual('v1');
    expect($breakdown['lines'][0]['amount_minor'])->toEqual(18742);
    expect($breakdown['lines'][0]['currency'])->toEqual('PLN');
});

test('a manual override on every covered service suppresses an unknown synced charge', function () {
    $service = Service::factory()->create();

    $unknown = CostItem::factory()->create([
        'source_kind' => SourceKind::Subscription,
        'amount_state' => AmountState::Unknown,
        'amount_minor' => null,
        'currency' => null,
        'logical_charge_key' => 'contabo:account:1:charge:contabo:resource:100001',
    ]);
    $unknown->services()->attach($service->id);

    $override = CostItem::factory()->create([
        'source_kind' => SourceKind::Manual,
        'is_manual_override' => true,
        'amount_minor' => 2150,
        'currency' => 'EUR',
        'logical_charge_key' => 'manual:charge:abc',
    ]);
    $override->services()->attach($service->id);

    $result = projectOn('2026-09-10');

    expect($result->unknownCount)->toBe(0)
        ->and($result->pricedCount())->toBe(1)
        ->and($result->forCurrency('EUR')->monthlyMinor)->toEqual(2150);
});

test('a manual override on some covered services leaves a package unknown counted', function () {
    [$covered, $uncovered] = Service::factory()->count(2)->create();

    $packageUnknown = CostItem::factory()->create([
        'source_kind' => SourceKind::Subscription,
        'amount_state' => AmountState::Unknown,
        'amount_minor' => null,
        'currency' => null,
        'logical_charge_key' => 'provider:account:1:charge:package',
    ]);
    $packageUnknown->services()->attach([$covered->id, $uncovered->id]);

    $override = CostItem::factory()->create([
        'source_kind' => SourceKind::Manual,
        'is_manual_override' => true,
        'amount_minor' => 1000,
        'currency' => 'EUR',
        'logical_charge_key' => 'manual:charge:abc',
    ]);
    $override->services()->attach($covered->id);

    $result = projectOn('2026-09-10');

    // The unknown still covers a service nobody answered for, so the
    // incompleteness stays counted.
    expect($result->unknownCount)->toBe(1)
        ->and($result->pricedCount())->toBe(1);
});

test('a plain manual charge without the override flag never suppresses an unknown', function () {
    $service = Service::factory()->create();

    $unknown = CostItem::factory()->create([
        'source_kind' => SourceKind::Subscription,
        'amount_state' => AmountState::Unknown,
        'amount_minor' => null,
        'currency' => null,
        'logical_charge_key' => 'contabo:account:1:charge:contabo:resource:100001',
    ]);
    $unknown->services()->attach($service->id);

    $addon = CostItem::factory()->create([
        'source_kind' => SourceKind::Manual,
        'is_manual_override' => false,
        'amount_minor' => 300,
        'currency' => 'EUR',
        'logical_charge_key' => 'manual:charge:addon',
    ]);
    $addon->services()->attach($service->id);

    $result = projectOn('2026-09-10');

    // An add-on documented by hand is not an answer to the provider's
    // unknown base price.
    expect($result->unknownCount)->toBe(1)
        ->and($result->pricedCount())->toBe(1);
});
