<?php

namespace Tests\Feature;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
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
        ->assertSeeHtml('aria-label="Last run:')
        ->assertSeeHtml('aria-label="Never synced')
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
        ->assertSee('OVHcloud')
        ->assertSee('failed')
        ->assertSee('failed during fetching inventory')
        ->assertSee('429 too many requests')
        ->assertSee('a sanitized note')
        ->assertSee('inventory: 12 seen, 3 new')
        ->assertSee('View all sync activity')
        ->assertSeeHtml('data-test="sync-details-retry"');
});

it('renders queued and running runs with only the timestamps they have', function () {
    $running = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    SyncRun::factory()->for($running)->running()->create();

    providersPage()
        ->call('openSyncDetails', $running->id)
        ->assertSee('running')
        ->assertSee('fetching inventory')
        ->assertDontSee('failed during')
        ->assertDontSee('Finished');

    $queued = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    SyncRun::factory()->for($queued)->queued()->create();

    providersPage()
        ->call('openSyncDetails', $queued->id)
        ->assertSee('queued')
        ->assertDontSee('Started')
        ->assertDontSee('Finished');
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

it('surfaces the stored cause of legacy failures that predate the error key', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    SyncRun::factory()->for($account)->create([
        'status' => SyncStatus::Failed,
        'summary' => ['warnings' => ['TransientProviderException: 429 too many requests']],
    ]);

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->assertSeeHtml('data-test="sync-details-error"')
        ->assertSee('429 too many requests');
});

it('never renders credential material from the stored payload', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(['payload' => ovhPayload()]), 'credentials')
        ->create(['provider_key' => 'ovh']);

    SyncRun::factory()->for($account)->failed()->create([
        'summary' => ['error' => 'TransientProviderException: 429 [redacted] rejected'],
    ]);

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->assertSee('[redacted]')
        ->assertDontSee(ovhPayload()['application_secret'])
        ->assertDontSee(ovhPayload()['consumer_key']);
});

it('retries a failed sync from the details modal through the shared entry point and refreshes the modal', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    SyncRun::factory()->for($account)->failed()->create();

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->call('syncNow', $account->id)
        ->assertSee('queued')
        ->assertDontSeeHtml('data-test="sync-details-retry"');

    Queue::assertPushed(SyncProviderAccount::class);
});

it('hides the retry action for an account with sync paused', function () {
    $account = ProviderAccount::factory()->create(['enabled' => false, 'provider_key' => 'ovh']);
    SyncRun::factory()->for($account)->failed()->create();

    providersPage()
        ->call('openSyncDetails', $account->id)
        ->assertDontSeeHtml('data-test="sync-details-retry"');
});

it('tolerates a retry click after the account was disconnected mid-session', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    $page = providersPage()->call('openSyncDetails', $account->id);
    $page->call('deleteAccount', $account->id)->assertSet('syncDetailsAccountId', null);

    $page->call('syncNow', $account->id);

    Queue::assertNotPushed(SyncProviderAccount::class);
});
