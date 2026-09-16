<?php

namespace Tests\Feature;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Enums\SyncStage;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Jobs\SyncProviderAccount;
use App\Domain\Sync\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function providersPage(): Testable
{
    return Livewire::test('pages::providers.index');
}

it('renders an accessible badge trigger for accounts with and without runs', function () {
    $withRun = ProviderAccount::factory()->create(['display_name' => 'OVH main']);
    SyncRun::factory()->for($withRun)->running()->create();

    ProviderAccount::factory()->create(['display_name' => 'Hetzner side']);

    providersPage()
        ->assertSeeHtml('aria-haspopup="dialog"')
        ->assertSeeHtml('wire:target="openSyncDetails')
        ->assertSeeHtml('data-test="sync-details-loading"')
        ->assertSee('Last run')
        ->assertSee('running')
        ->assertSee('Never synced');
});

it('opens the most recent synchronization details from the badge', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVH main', 'provider_key' => 'ovh']);

    SyncRun::factory()->for($account)->create([
        'status' => SyncStatus::Failed,
        'stage' => SyncStage::Inventory,
        'trigger' => 'schedule',
        'summary' => [
            'error' => 'TransientProviderException: 429 too many requests (after 4 attempts)',
            'warnings' => ['a sanitized note'],
        ],
        'counts' => ['inventory' => ['seen' => 12, 'created' => 3, 'updated' => 1]],
    ]);

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->assertSet('syncDetailsAccountId', $account->id)
        ->assertSee('OVH main')
        ->assertSee('failed')
        ->assertSee('failed during fetching inventory')
        ->assertSee('429 too many requests')
        ->assertSee('a sanitized note')
        ->assertSee('inventory: 12 seen, 3 new')
        ->assertSee('View all sync activity')
        ->assertSeeHtml('data-test="sync-details-retry"');
});

it('shows a clear empty state when no synchronization has run yet', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->assertSee('No synchronization has run')
        ->assertSeeHtml('data-test="sync-details-empty"')
        ->assertDontSeeHtml('data-test="sync-details-retry"');
});

it('shows the latest run even when the page rendered before it existed', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    SyncRun::factory()->for($account)->create(['status' => SyncStatus::Succeeded]);

    $page = providersPage()->call('openSyncDetails', $account->id);
    $page->assertSee('succeeded');

    SyncRun::factory()->for($account)->failed()->create(['summary' => ['error' => 'a newer failure']]);

    $page->call('openSyncDetails', $account->id)
        ->assertSee('a newer failure')
        ->assertDontSee('succeeded');
});

it('never renders credential material from the stored summary', function () {
    // The run's summary is redacted at write time; the modal must not
    // reach around it to raw payloads.
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    SyncRun::factory()->for($account)->failed()->create([
        'summary' => ['error' => 'TransientProviderException: 429 [redacted] rejected'],
    ]);

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->assertSee('[redacted]')
        ->assertDontSee('supersecretvalue');
});

it('retries a failed sync from the details modal through the shared entry point', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    SyncRun::factory()->for($account)->failed()->create();

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->call('syncNow', $account->id);

    Queue::assertPushed(SyncProviderAccount::class);
});
