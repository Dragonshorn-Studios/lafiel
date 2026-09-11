<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('service identity is owned by a database constraint', function () {
    $account = ProviderAccount::factory()->create();

    Service::factory()->discovered($account)->create(['external_id' => 'vm-abc-123']);
    Service::factory()->discovered($account)->create(['external_id' => 'vm-abc-123']);
})->throws(QueryException::class);

test('the same external id under a different account is a different service', function () {
    $first = ProviderAccount::factory()->create();
    $second = ProviderAccount::factory()->create();

    Service::factory()->discovered($first)->create(['external_id' => 'vm-abc-123']);
    Service::factory()->discovered($second)->create(['external_id' => 'vm-abc-123']);

    expect(Service::query()->count())->toEqual(2);
});

test('manual services without provider identity never collide', function () {
    Service::factory()->create(['name' => 'AI subscription']);
    Service::factory()->create(['name' => 'Domain renewal']);

    expect(Service::query()->count())->toEqual(2);
});

test('cost item identity is owned by a database constraint', function () {
    CostItem::factory()->create(['identity_key' => 'ovh:invoice:123:2026-09']);
    CostItem::factory()->create(['identity_key' => 'ovh:invoice:123:2026-09']);
})->throws(QueryException::class);

test('competing evidence for one logical charge may coexist', function () {
    CostItem::factory()->subscriptionQuote()->create(['logical_charge_key' => 'charge:vps-01:monthly']);
    CostItem::factory()->invoiceActual()->create(['logical_charge_key' => 'charge:vps-01:monthly']);

    expect(CostItem::query()->where('logical_charge_key', 'charge:vps-01:monthly')->count())->toEqual(2);
});

test('capability state is unique per account and capability', function () {
    $account = ProviderAccount::factory()->create();

    ProviderCapabilityState::factory()->create([
        'provider_account_id' => $account->id,
        'capability_key' => 'inventory',
    ]);
    ProviderCapabilityState::factory()->create([
        'provider_account_id' => $account->id,
        'capability_key' => 'inventory',
    ]);
})->throws(QueryException::class);

test('canonical lifecycle values are enforced by the database', function () {
    // Insert below Eloquent: the enum cast rejects invalid values first,
    // the CHECK constraint is the backstop for raw writers.
    DB::table('services')->insert([
        'category' => 'compute',
        'name' => 'Bypassed service',
        'lifecycle_state' => 'vanished',
    ]);
})->throws(QueryException::class);

test('canonical source kinds are enforced by the database', function () {
    DB::table('cost_items')->insert([
        'identity_key' => 'bypass:1',
        'logical_charge_key' => 'bypass:1',
        'source_kind' => 'manual2',
        'charge_kind' => 'recurring_fixed',
        'amount_state' => 'known',
        'evidence_state' => 'manual',
        'valid_from' => today(),
    ]);
})->throws(QueryException::class);
