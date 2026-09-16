<?php

namespace Tests\Feature;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Enums\SyncStage;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Jobs\SyncProviderAccount;
use App\Domain\Sync\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;

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
    $this->travelTo(now()->setTime(10, 0));

    SyncRun::factory()->create([
        'status' => SyncStatus::Succeeded,
        'finished_at' => now()->setTime(4, 0, 12),
    ]);

    syncsPage()
        ->assertSee('Next scheduled sync '.now()->addDay()->setTime(4, 0)->format('Y-m-d H:i'))
        ->assertSee('last successful '.now()->setTime(4, 0)->format('Y-m-d H:i'));
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

it('reports when a retry could not be queued', function () {
    $account = ProviderAccount::factory()->create();
    $run = SyncRun::factory()->for($account)->failed()->create();

    Bus::shouldReceive('dispatch')
        ->andThrow(new RuntimeException('queue connection refused'));

    syncsPage()->call('retry', $run->id);

    // The attempted run is marked failed, not left queued.
    expect(SyncRun::query()->where('status', SyncStatus::Queued->value)->exists())->toBeFalse()
        ->and(SyncRun::query()->where('status', SyncStatus::Failed->value)->count())->toBe(2);
});

it('tolerates a retry click on a run that no longer exists', function () {
    Queue::fake();

    $account = ProviderAccount::factory()->create();
    $run = SyncRun::factory()->for($account)->failed()->create();
    $run->delete();

    syncsPage()->call('retry', $run->id);

    Queue::assertNotPushed(SyncProviderAccount::class);
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

    // An in-flight run for the other account stays visible in the
    // active section whatever the history filter says.
    SyncRun::factory()->for($hetzner)->queued()->create();

    syncsPage()
        ->set('accountFilter', (string) $ovh->id)
        ->assertSee('ovh exploded spectacularly')
        ->assertDontSee('7 seen · 2 new')
        ->assertSee('In progress');

    syncsPage()
        ->set('statusFilter', SyncStatus::Succeeded->value)
        ->assertSee('7 seen · 2 new')
        ->assertDontSee('ovh exploded spectacularly');
});

it('shows the raw provider key for a provider that no longer has a schema', function () {
    SyncRun::factory()->for(ProviderAccount::factory()->create(['provider_key' => 'discontinued']))->failed()->create();

    syncsPage()->assertSee('discontinued');
});

it('formats durations, cost counts, and warnings', function () {
    SyncRun::factory()->for(ProviderAccount::factory()->create())->create([
        'status' => SyncStatus::Partial,
        'stage' => SyncStage::Persisting,
        'started_at' => now()->subMinutes(3)->subSeconds(5),
        'finished_at' => now(),
        'counts' => [
            'inventory' => ['seen' => 4, 'created' => 1, 'updated' => 0],
            'cost_facts' => ['seen' => 9, 'created' => 6, 'updated' => 0, 'superseded' => 1],
        ],
        'summary' => ['warnings' => ['wibble', 'wobble']],
    ]);

    syncsPage()
        ->assertSee('3m 05s')
        ->assertSee('cost facts 9 seen · 6 new')
        ->assertSee('2 warning(s)');
});

it('surfaces the failure cause of legacy runs that predate the error key', function () {
    SyncRun::factory()->for(ProviderAccount::factory()->create())->create([
        'status' => SyncStatus::Failed,
        'summary' => ['warnings' => ['TransientProviderException: 429 too many requests']],
    ]);

    syncsPage()->assertSee('429 too many requests');
});

it('hints that a long-queued run may be abandoned', function () {
    $account = ProviderAccount::factory()->create();

    SyncRun::factory()->for($account)->queued()->create([
        'created_at' => now()->subHours(2),
    ]);

    syncsPage()->assertSee('this run may be abandoned');
});

it('paginates the history and resets the page when a filter changes', function () {
    $account = ProviderAccount::factory()->create();

    // Trigger markers instead of run ids: the autoincrement counter is
    // shared with every other test in the process, so ids are not stable.
    SyncRun::factory()->for($account)->count(20)->sequence(
        fn ($sequence) => ['trigger' => sprintf('marker %02d', $sequence->index + 1)],
    )->create();

    $page = syncsPage()
        ->assertSee('marker 20')
        ->assertDontSee('marker 05')
        ->assertDontSee('marker 01');

    $page->call('setPage', 2)
        ->assertSee('marker 05')
        ->assertSee('marker 01')
        ->assertDontSee('marker 20');

    // Filtering from page 2 lands back on page 1 of the filtered set.
    $page->set('accountFilter', (string) $account->id)
        ->assertSee('marker 20')
        ->assertDontSee('marker 05');
});
