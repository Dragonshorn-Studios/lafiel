<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\SubscriptionPreset;
use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Inventory\ServiceLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SubscriptionPresetSeeder;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-27 12:00:00');
    $this->seed(SubscriptionPresetSeeder::class);
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the library ships racknerd annual vps tiers as editable starting points', function () {
    $preset = SubscriptionPreset::query()->where('key', 'racknerd:kvm_1gb_annual')->firstOrFail();

    expect($preset->vendor)->toBe('RackNerd')
        ->and($preset->category)->toBe('compute')
        ->and($preset->period->value)->toBe('annual')
        ->and($preset->auto_renew)->toBeTrue()
        ->and($preset->amount_minor)->toBe(1199)
        ->and($preset->currency)->toBe('USD');
});

test('a racknerd vps becomes a tracked recurring cost with zero provider credentials', function () {
    $preset = SubscriptionPreset::query()->where('key', 'racknerd:kvm_1gb_annual')->firstOrFail();

    Livewire::test('pages::costs.index')
        ->set('presetId', $preset->id)
        ->call('save');

    $service = Service::query()->where('vendor', 'RackNerd')->firstOrFail();

    // The whole point: no provider account behind it — the manual
    // path is first-class for API-less providers.
    expect($service->provider_account_id)->toBeNull()
        ->and($service->name)->toBe('KVM 1GB')
        ->and($service->category)->toBe('compute')
        ->and($service->lifecycle_state)->toBe(ServiceLifecycle::Active);

    $costItem = CostItem::query()->whereHas('services', fn ($q) => $q->where('services.id', $service->id))->firstOrFail();

    expect($costItem->amount_minor)->toBe(1199)
        ->and($costItem->currency)->toBe('USD')
        ->and($costItem->period->value)->toBe('annual')
        ->and($costItem->charge_kind->value)->toBe('recurring_fixed')
        ->and($costItem->valid_to)->toBeNull();

    // Annual period + auto-renew derives the renewal one year out.
    expect($costItem->renewal)->not->toBeNull()
        ->and($costItem->renewal->renews_at->format('Y-m-d'))->toBe('2027-09-27')
        ->and($costItem->renewal->auto_renew)->toBeTrue();

    // The ledger carries it at the exact annual→monthly equivalent.
    $row = collect(app(ServiceLedger::class)->rows(now()))
        ->first(fn ($row) => $row->service->id === $service->id);

    expect($row)->not->toBeNull()
        ->and($row->monthlyMinor)->toBe(100) // 1199 / 12, rounded once half-even
        ->and($row->monthlyCurrency)->toBe('USD');
});
