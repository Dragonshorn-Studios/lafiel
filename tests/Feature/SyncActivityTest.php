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

function syncsPage(): Testable
{
    return Livewire::test('pages::sync.index');
}

it('serves the sync activity page only to authenticated visitors', function () {
    $user = auth()->user();
    auth()->logout();

    $this->get(route('syncs.index'))->assertRedirect(route('login'));

    $this->actingAs($user)->get(route('syncs.index'))->assertOk();
});

it('lists finished runs with their account, status, stage, and error', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVH main']);

    SyncRun::factory()->for($account)->create([
        'status' => SyncStatus::Failed,
        'stage' => SyncStage::Inventory,
        'summary' => ['error' => 'TransientProviderException: 429 too many requests (after 4 attempts)'],
    ]);

    SyncRun::factory()->for($account)->create([
        'status' => SyncStatus::Succeeded,
        'stage' => SyncStage::Persisting,
        'counts' => ['inventory' => ['seen' => 12, 'created' => 3, 'updated' => 1]],
    ]);

    syncsPage()
        ->assertSee('OVH main')
        ->assertSee('failed')
        ->assertSee('fetching inventory')
        ->assertSee('429 too many requests')
        ->assertSee('12 seen · 3 new · 1 updated');
});

it('shows queued and running runs in the in-progress section and polls while work is in flight', function () {
    // One active run per account — the partial unique index enforces it.
    SyncRun::factory()->for(ProviderAccount::factory()->create(['display_name' => 'OVH main']))->queued()->create();
    SyncRun::factory()->for(ProviderAccount::factory()->create(['display_name' => 'Hetzner side']))->running()->create();

    syncsPage()
        ->assertSeeHtml('wire:poll')
        ->assertSee('In progress')
        ->assertSee('OVH main')
        ->assertSee('Hetzner side')
        ->assertSee('fetching inventory');
});

it('does not poll when no run is active', function () {
    SyncRun::factory()->create();

    syncsPage()->assertDontSeeHtml('wire:poll');
});

it('shows the next scheduled sync and the last successful finish', function () {
    $finishedAt = now()->setTime(4, 0, 12);
    SyncRun::factory()->create(['status' => SyncStatus::Succeeded, 'finished_at' => $finishedAt]);

    syncsPage()
        ->assertSee('Next scheduled sync')
        ->assertSee('last successful');
});

it('shows an empty state when nothing has run yet', function () {
    syncsPage()->assertSee('Nothing here');
});

it('retries a failed run through the shared entry point', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create();
    $run = SyncRun::factory()->for($account)->failed()->create();

    syncsPage()->call('retry', $run->id);

    Queue::assertPushed(SyncProviderAccount::class);
    expect(SyncRun::query()->where('status', SyncStatus::Queued->value)->count())->toBe(1);
});

it('refuses to retry when the account has sync paused', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create(['enabled' => false]);
    $run = SyncRun::factory()->for($account)->failed()->create();

    syncsPage()->call('retry', $run->id);

    Queue::assertNotPushed(SyncProviderAccount::class);
    expect(SyncRun::query()->where('status', SyncStatus::Queued->value)->exists())->toBeFalse();
});

it('refuses to retry while the account already has an active run', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create();
    SyncRun::factory()->for($account)->running()->create();
    $failed = SyncRun::factory()->for($account)->failed()->create();

    syncsPage()->call('retry', $failed->id);

    Queue::assertNotPushed(SyncProviderAccount::class);
    expect(SyncRun::query()->where('status', SyncStatus::Queued->value)->exists())->toBeFalse();
});

it('filters history by account and status', function () {
    $ovh = ProviderAccount::factory()->create();
    $hetzner = ProviderAccount::factory()->create();

    SyncRun::factory()->for($ovh)->failed()->create([
        'summary' => ['error' => 'ovh exploded spectacularly'],
    ]);
    SyncRun::factory()->for($hetzner)->create([
        'status' => SyncStatus::Succeeded,
        'counts' => ['inventory' => ['seen' => 7, 'created' => 2, 'updated' => 0]],
    ]);

    syncsPage()
        ->set('accountFilter', (string) $ovh->id)
        ->assertSee('ovh exploded spectacularly')
        ->assertDontSee('7 seen · 2 new');

    syncsPage()
        ->set('statusFilter', SyncStatus::Succeeded->value)
        ->assertSee('7 seen · 2 new')
        ->assertDontSee('ovh exploded spectacularly');
});

it('paginates the history', function () {
    $account = ProviderAccount::factory()->create();
    SyncRun::factory()->for($account)->count(20)->create();

    $page = syncsPage()
        ->assertSee('#20')
        ->assertDontSee('#5');

    $page->call('setPage', 2)
        ->assertSee('#5')
        ->assertDontSee('#20');
});
