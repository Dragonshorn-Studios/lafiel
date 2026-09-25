<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use App\Models\User;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->actingAs(User::factory()->create());
});

test('the service detail shows origin, packages, and charge history', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $service = Service::factory()->discovered($account)->create(['name' => 'vps-atlas-01']);
    $other = Service::factory()->discovered($account)->create(['name' => 'backup-storage']);

    // A shared, open quote plus an ended older version.
    $open = CostItem::factory()->subscriptionQuote()->create([
        'amount_minor' => 4900,
        'currency' => 'PLN',
        'source_ref' => 'ovh:renewal:vps-atlas-01',
        'observed_at' => now(),
    ]);
    $open->services()->attach([$service->id, $other->id]);

    $ended = CostItem::factory()->create([
        'amount_minor' => 3900,
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-08-31',
        'observed_at' => now(),
    ]);
    $ended->services()->attach($service->id);

    Renewal::query()->create([
        'cost_item_id' => $open->id,
        'renews_at' => '2026-10-01',
        'auto_renew' => true,
    ]);

    $this->get(route('services.show', $service))
        ->assertOk()
        ->assertSee('vps-atlas-01')
        ->assertSee('OVHcloud')
        ->assertSee('ovh:renewal:vps-atlas-01')
        ->assertSee('Open')
        ->assertSee('Ended')
        ->assertSee(__('Covers :n services:', ['n' => 2]), false)
        ->assertSee('backup-storage')
        ->assertSee(__('Next renewal :date (:auto).', ['date' => '2026-10-01', 'auto' => __('automatic')]), false);
});

test('an unknown service 404s', function () {
    $this->get('/services/424242')->assertNotFound();
});

test('provider cards show health per capability', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    ProviderCapabilityState::factory()->create([
        'provider_account_id' => $account->id,
        'capability_key' => 'inventory',
        'supported' => true,
        'healthy' => true,
    ]);

    ProviderCapabilityState::factory()->create([
        'provider_account_id' => $account->id,
        'capability_key' => 'renewal_quotes',
        'supported' => true,
        'healthy' => false,
    ]);

    $this->get(route('providers.index'))
        ->assertOk()
        ->assertSee('Inventory')
        ->assertSee('Renewals')
        ->assertSee('Subscriptions')
        ->assertSee('Usage')
        ->assertSee('Invoices')
        ->assertSee('capability-health', false);
});

test('the services view filters zero cost items by default and toggles them', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $vps = Service::factory()->discovered($account)->create(['name' => 'vps-main']);
    $ip = Service::factory()->discovered($account)->create(['name' => 'vps-ip-free']);

    $priced = CostItem::factory()->create(['amount_minor' => 1500, 'currency' => 'EUR', 'observed_at' => now()]);
    $priced->services()->attach($vps->id);

    $free = CostItem::factory()->create(['amount_minor' => 0, 'currency' => 'EUR', 'observed_at' => now()]);
    $free->services()->attach($ip->id);

    // Default: hideZeroCost is true -> free IP service is hidden, paid VPS is visible
    $component = Livewire::test('pages::costs.index');

    $names = collect($component->get('rows'))->map(fn ($r) => $r->service->name);
    expect($names)->toContain('vps-main')->and($names)->not->toContain('vps-ip-free');

    $component->set('hideZeroCost', false);

    $allNames = collect($component->get('rows'))->map(fn ($r) => $r->service->name);
    expect($allNames)->toContain('vps-main')->and($allNames)->toContain('vps-ip-free');
});

test('the renewals view filters zero cost items by default and toggles them', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $vps = Service::factory()->discovered($account)->create(['name' => 'vps-main']);
    $ip = Service::factory()->discovered($account)->create(['name' => 'vps-ip-free']);

    $priced = CostItem::factory()->create(['amount_minor' => 1500, 'currency' => 'EUR', 'observed_at' => now()]);
    $priced->services()->attach($vps->id);
    Renewal::query()->create(['cost_item_id' => $priced->id, 'renews_at' => now()->addDays(5), 'auto_renew' => true]);

    $free = CostItem::factory()->create(['amount_minor' => 0, 'currency' => 'EUR', 'observed_at' => now()]);
    $free->services()->attach($ip->id);
    Renewal::query()->create(['cost_item_id' => $free->id, 'renews_at' => now()->addDays(5), 'auto_renew' => true]);

    $component = Livewire::test('pages::costs.renewals');

    $renewals = $component->get('renewals')->map(fn ($r) => $r->costItem->services->first()?->name);
    expect($renewals)->toContain('vps-main')->and($renewals)->not->toContain('vps-ip-free');

    $component->set('hideZeroCost', false);

    $allRenewals = $component->get('renewals')->map(fn ($r) => $r->costItem->services->first()?->name);
    expect($allRenewals)->toContain('vps-main')->and($allRenewals)->toContain('vps-ip-free');
});

test('the renewals view lists provider and source amount', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $service = Service::factory()->discovered($account)->create(['name' => 'vps-atlas-01']);

    $priced = CostItem::factory()->create(['amount_minor' => 4900, 'currency' => 'PLN', 'observed_at' => now()]);
    $priced->services()->attach($service->id);
    Renewal::query()->create(['cost_item_id' => $priced->id, 'renews_at' => now()->addDays(5), 'auto_renew' => true]);

    $unknown = CostItem::factory()->unknownAmount()->create(['observed_at' => now()]);
    Renewal::query()->create(['cost_item_id' => $unknown->id, 'renews_at' => now()->addDays(9), 'auto_renew' => false]);

    $this->get(route('costs.renewals'))
        ->assertOk()
        ->assertSee('OVHcloud')
        ->assertSee('vps-atlas-01')
        ->assertSee('49.00')
        ->assertSee(__('unknown'));
});
