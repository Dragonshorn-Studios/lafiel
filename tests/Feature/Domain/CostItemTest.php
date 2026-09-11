<?php

use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Support\ValueObjects\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('invoice actual outranks usage actual', function () {
    $invoice = CostItem::factory()->invoiceActual()->create();
    $usage = CostItem::factory()->usageActual()->create();

    expect($invoice->outranks($usage))->toBeTrue();
    expect($usage->outranks($invoice))->toBeFalse();
});

test('usage actual outranks a subscription quote', function () {
    $usage = CostItem::factory()->usageActual()->create();
    $quote = CostItem::factory()->subscriptionQuote()->create();

    expect($usage->outranks($quote))->toBeTrue();
});

test('an invoice-sourced quote does not outrank a usage actual', function () {
    $invoiceQuote = CostItem::factory()->create([
        'source_kind' => SourceKind::Invoice,
        'evidence_state' => EvidenceState::Quote,
    ]);
    $usage = CostItem::factory()->usageActual()->create();

    expect($invoiceQuote->outranks($usage))->toBeFalse();
    expect($usage->outranks($invoiceQuote))->toBeTrue();
});

test('plain manual evidence loses to everything but another manual', function () {
    $manual = CostItem::factory()->create();
    $quote = CostItem::factory()->subscriptionQuote()->create();

    expect($quote->outranks($manual))->toBeTrue();
    expect($manual->outranks($quote))->toBeFalse();
});

test('a conscious manual override outranks an invoice actual', function () {
    $override = CostItem::factory()->manualOverride()->create();
    $invoice = CostItem::factory()->invoiceActual()->create();

    expect($override->outranks($invoice))->toBeTrue();
});

test('equal evidence breaks the tie on observation time', function () {
    $older = CostItem::factory()->subscriptionQuote()->create([
        'logical_charge_key' => 'charge:tie',
        'observed_at' => '2026-09-01 06:00:00',
    ]);
    $newer = CostItem::factory()->subscriptionQuote()->create([
        'logical_charge_key' => 'charge:tie',
        'observed_at' => '2026-09-05 06:00:00',
    ]);

    expect($newer->outranks($older))->toBeTrue();
    expect($older->outranks($newer))->toBeFalse();
});

test('a manual override flag on non-manual evidence is rejected by the database', function () {
    DB::table('cost_items')->insert([
        'identity_key' => 'guard:1',
        'logical_charge_key' => 'guard:1',
        'source_kind' => 'invoice',
        'charge_kind' => 'recurring_fixed',
        'period' => 'monthly',
        'amount_minor' => 1000,
        'currency' => 'PLN',
        'amount_state' => 'known',
        'evidence_state' => 'actual',
        'is_manual_override' => true,
        'valid_from' => today(),
    ]);
})->throws(QueryException::class);

test('a known amount without its columns is rejected by the database', function () {
    DB::table('cost_items')->insert([
        'identity_key' => 'guard:2',
        'logical_charge_key' => 'guard:2',
        'source_kind' => 'manual',
        'charge_kind' => 'recurring_fixed',
        'period' => 'monthly',
        'amount_state' => 'known',
        'evidence_state' => 'manual',
        'valid_from' => today(),
    ]);
})->throws(QueryException::class);

test('money accepts lowercase currency codes and normalizes them', function () {
    expect(Money::ofMinor(100, 'usd')->currency)->toEqual('USD');
    expect((new Money(100, 'pln'))->currency)->toEqual('PLN');
});

test('money rejects amounts beyond the integer minor-unit range', function () {
    Money::ofString('99999999999999999999.99', 'PLN');
})->throws(InvalidArgumentException::class);
