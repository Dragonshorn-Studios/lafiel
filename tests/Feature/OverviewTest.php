<?php

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Sync\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->actingAs(User::factory()->create());
});

test('guests are redirected to the login page', function () {
    auth()->logout();

    $this->get(route('overview'))->assertRedirect(route('login'));
});

test('authenticated users can visit the overview', function () {
    $this->get(route('overview'))->assertOk();
});

test('the dashboard alias leads to the overview', function () {
    $this->get('/dashboard')->assertRedirect(route('overview'));
});

test('the shell renders the imperial ledger navigation', function () {
    $response = $this->get(route('overview'));

    $response->assertOk()
        ->assertSee(__('Fleet ledger'))
        ->assertSee(__('Overview'))
        ->assertSee(__('Services'))
        ->assertSee(__('Renewals'))
        ->assertSee(__('History'))
        ->assertSee(__('Providers'))
        ->assertSee(__('Settings'))
        ->assertSee('v'.config('app.version'))
        ->assertSee(__('Not synced yet'));
});

test('the overview paints the projection without calculating money', function () {
    // One priced manual charge and one with unknown pricing:
    // 50.00 PLN monthly, coverage 1 priced / 1 unknown.
    createManualCost();
    createUnknownCost();

    $overview = Livewire\Livewire::test('pages::overview.index');

    expect($overview->get('summary')['monthly'])->toBe('50.00 PLN/mo + 1 unknown')
        ->and($overview->get('projection')->pricedCount())->toBe(1)
        ->and($overview->get('projection')->unknownCount)->toBe(1);
});

test('the overview shows the incompleteness band and coverage', function () {
    createManualCost();
    createUnknownCost();

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee(__('Monthly total is incomplete — pricing is unknown for :n services.', ['n' => 1]), false)
        ->assertSee(__('priced / :n unknown', ['n' => 1]), false);
});

test('the overview lists renewals due within 30 days', function () {
    $item = createManualCost();

    Renewal::query()->create([
        'cost_item_id' => $item->id,
        'renews_at' => now()->addDays(10),
        'auto_renew' => false,
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee(__('Upcoming renewals'))
        ->assertSee(now()->addDays(10)->format('Y-m-d'))
        ->assertSee(__('View all renewals'));
});

test('the overview groups spend by provider', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh', 'display_name' => 'OVHcloud']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id]);

    $service = Service::factory()->discovered($account)->create();

    createManualCost(['covers_service_id' => $service->id]);

    $overview = Livewire\Livewire::test('pages::overview.index');

    expect($overview->get('split'))->toBe(['OVHcloud' => 5000]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('OVHcloud');
});

test('sync now queues a run for every enabled account through the shared entry point', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh', 'enabled' => true]);
    ProviderAccount::factory()->create(['provider_key' => 'ovh', 'enabled' => false]);

    Livewire\Livewire::test('shell.sync-button')->call('syncNow');

    expect(SyncRun::query()->where('provider_account_id', $account->id)->count())->toBe(1)
        ->and(SyncRun::query()->count())->toBe(1);
});

test('the sync state reports the freshest success', function () {
    ProviderAccount::factory()->synced()->create(['provider_key' => 'ovh']);

    $state = Livewire\Livewire::test('shell.sync-state');

    expect($state->get('lastSyncedAt'))->not->toBeNull()
        ->and($state->get('allFresh'))->toBeTrue();
});

/**
 * A 50.00 PLN monthly manual charge, mirroring the costs page helper.
 */
function createManualCost(array $overrides = []): CostItem
{
    return app(CreateManualCost::class)->create([
        'vendor' => null,
        'name' => 'Manual service',
        'category' => 'saas',
        'unknown_amount' => false,
        'amount' => '50.00',
        'currency' => 'PLN',
        'period' => 'monthly',
        'valid_from' => now()->subDays(5)->toDateString(),
        'valid_to' => null,
        'renews_at' => null,
        'auto_renew' => false,
        'url' => null,
        'notes' => null,
        'covers_service_id' => null,
        ...$overrides,
    ]);
}

function createUnknownCost(): CostItem
{
    return createManualCost(['name' => 'Unknown service', 'unknown_amount' => true, 'amount' => null]);
}
