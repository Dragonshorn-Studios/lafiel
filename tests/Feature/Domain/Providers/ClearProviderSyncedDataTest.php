<?php

use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Actions\ClearProviderSyncedData;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;

function accountWithSyncedOvhData(): ProviderAccount
{
    $account = ProviderAccount::factory()->create([
        'provider_key' => 'ovh',
        'last_attempt_at' => now(),
        'last_success_at' => now(),
    ]);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id]);
    ProviderCapabilityState::factory()->create(['provider_account_id' => $account->id]);
    SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => SyncStatus::Succeeded,
    ]);

    $service = Service::factory()->discovered($account)->create();

    CostItem::factory()->create([
        'logical_charge_key' => sprintf('ovh:account:%d:charge:ovh:renew:400010001', $account->id),
        'source_kind' => SourceKind::RenewalQuote,
        'amount_minor' => 900,
        'currency' => 'EUR',
    ])->services()->attach($service->id);

    CostItem::factory()->manualOverride()->create([
        'logical_charge_key' => 'manual:charge:overlay-of-discovered',
        'source_kind' => SourceKind::Manual,
        'amount_minor' => 999,
        'currency' => 'EUR',
    ])->services()->attach($service->id);

    return $account;
}

it('wipes discovered services and provider charges while keeping credentials', function () {
    $account = accountWithSyncedOvhData();
    $manual = Service::factory()->create(['name' => 'hand-entered']);
    CostItem::factory()->create([
        'logical_charge_key' => 'manual:charge:independent',
        'amount_minor' => 1200,
        'currency' => 'PLN',
    ])->services()->attach($manual->id);

    $cleared = app(ClearProviderSyncedData::class)->clear($account);

    expect($cleared)->toBeTrue()
        ->and($account->refresh()->credentials)->toHaveCount(1)
        ->and($account->last_attempt_at)->toBeNull()
        ->and($account->last_success_at)->toBeNull()
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(0)
        ->and(CostItem::query()->where('logical_charge_key', 'like', 'ovh:account:'.$account->id.':charge:%')->count())->toBe(0)
        ->and(CostItem::query()->where('logical_charge_key', 'manual:charge:overlay-of-discovered')->exists())->toBeFalse()
        ->and(SyncRun::query()->where('provider_account_id', $account->id)->count())->toBe(0)
        ->and(ProviderCapabilityState::query()->where('provider_account_id', $account->id)->count())->toBe(0)
        ->and(Service::query()->whereKey($manual->id)->exists())->toBeTrue()
        ->and(CostItem::query()->where('logical_charge_key', 'manual:charge:independent')->exists())->toBeTrue();
});

it('leaves another account\'s synced charges untouched', function () {
    $account = accountWithSyncedOvhData();
    $other = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    $otherService = Service::factory()->discovered($other)->create();
    CostItem::factory()->create([
        'logical_charge_key' => sprintf('ovh:account:%d:charge:ovh:renew:keep', $other->id),
        'source_kind' => SourceKind::RenewalQuote,
        'amount_minor' => 400,
        'currency' => 'EUR',
    ])->services()->attach($otherService->id);

    app(ClearProviderSyncedData::class)->clear($account);

    expect(Service::query()->whereKey($otherService->id)->exists())->toBeTrue()
        ->and(CostItem::query()->where('logical_charge_key', sprintf('ovh:account:%d:charge:ovh:renew:keep', $other->id))->exists())->toBeTrue();
});

it('refuses to wipe while a sync is still queued or running', function (SyncStatus $status) {
    $account = accountWithSyncedOvhData();
    SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => $status,
        'started_at' => now(),
    ]);

    $cleared = app(ClearProviderSyncedData::class)->clear($account);

    expect($cleared)->toBeFalse()
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(1)
        ->and($account->refresh()->credentials)->toHaveCount(1);
})->with([
    SyncStatus::Queued,
    SyncStatus::Running,
]);
