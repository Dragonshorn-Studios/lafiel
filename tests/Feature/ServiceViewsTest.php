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

test('the services view groups same-name rows under one header with the summed monthly', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $domain = Service::factory()->discovered($account)->create(['name' => 'example.com']);
    $email = Service::factory()->discovered($account)->create(['name' => 'example.com']);
    $vps = Service::factory()->discovered($account)->create(['name' => 'vps-main']);

    CostItem::factory()->create(['amount_minor' => 4900, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($domain->id);
    CostItem::factory()->create(['amount_minor' => 1500, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($email->id);
    CostItem::factory()->create(['amount_minor' => 2000, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($vps->id);

    $groups = Livewire::test('pages::costs.index')->get('groupedRows');

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['label'])->toBe('example.com')
        ->and($groups[0]['provider'])->toBe('OVHcloud')
        ->and($groups[0]['monthly'])->toBe('64.00 PLN/mo')
        ->and(count($groups[0]['rows']))->toBe(2)
        ->and($groups[1]['label'])->toBe('vps-main')
        ->and($groups[1]['monthly'])->toBe('20.00 PLN/mo');

    $this->get(route('costs.index'))
        ->assertOk()
        ->assertSee('64.00 PLN/mo');
});

test('the same name under different providers stays in separate groups', function () {
    $ovh = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $mikrus = ProviderAccount::factory()->create(['provider_key' => 'mikrus', 'display_name' => 'Mikr.us']);

    $ovhService = Service::factory()->discovered($ovh)->create(['name' => 'example.com']);
    $mikrusService = Service::factory()->discovered($mikrus)->create(['name' => 'example.com']);

    CostItem::factory()->create(['amount_minor' => 4900, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($ovhService->id);
    CostItem::factory()->create(['amount_minor' => 1500, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($mikrusService->id);

    $groups = collect(Livewire::test('pages::costs.index')->get('groupedRows'));

    // Two equal-name rows have no SQL-guaranteed relative order, so
    // the assertion is order-insensitive on purpose.
    expect($groups)->toHaveCount(2)
        ->and($groups->pluck('label'))->each->toBe('example.com')
        ->and($groups->pluck('provider')->sort()->values()->all())->toBe(['Mikr.us', 'OVHcloud']);
});

test('the services view buckets uuid-like names together, collapsed by default', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $dashed = Service::factory()->discovered($account)->create(['name' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d']);
    $contiguous = Service::factory()->discovered($account)->create(['name' => '0f1e2d3c4b5a69788796a5b4c3d2e1f0']);
    $named = Service::factory()->discovered($account)->create(['name' => 'vps-main']);

    CostItem::factory()->create(['amount_minor' => 100, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($dashed->id);
    CostItem::factory()->create(['amount_minor' => 200, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($contiguous->id);
    CostItem::factory()->create(['amount_minor' => 2000, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($named->id);

    $component = Livewire::test('pages::costs.index');
    $groups = $component->get('groupedRows');

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['label'])->toBe('vps-main')
        ->and($groups[1]['unlabeled'])->toBeTrue()
        ->and(count($groups[1]['rows']))->toBe(2);

    // The UUID names also feed the form panel's overlay select, so the
    // collapsed state is asserted through the child rows' detail links.
    // The toggle itself is Livewire's framework-handled magic action —
    // the test harness cannot call `$toggle` directly, so the state
    // change it performs is driven via the property.
    $component->assertSee(__('Unlabeled / other'))
        ->assertDontSee('/services/'.$dashed->id)
        ->set('unlabeledOpen', true)
        ->assertSee('/services/'.$dashed->id)
        ->assertSee('/services/'.$contiguous->id);
});

test('the services view sums mixed-currency groups per currency without converting', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $first = Service::factory()->discovered($account)->create(['name' => 'example.com']);
    $second = Service::factory()->discovered($account)->create(['name' => 'example.com']);

    CostItem::factory()->create(['amount_minor' => 300, 'currency' => 'EUR', 'observed_at' => now()])->services()->attach($first->id);
    CostItem::factory()->create(['amount_minor' => 200, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($second->id);

    $groups = collect(Livewire::test('pages::costs.index')->get('groupedRows'));

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['monthly'])->toContain('3.00 EUR')
        ->and($groups[0]['monthly'])->toContain('2.00 PLN')
        ->and($groups[0]['monthly'])->toContain(' + ')
        ->and($groups[0]['monthly'])->toEndWith('/mo');
});

test('empty display names share the unlabeled bucket with uuid-like names', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $empty = Service::factory()->discovered($account)->create(['name' => '']);
    $uuid = Service::factory()->discovered($account)->create(['name' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d']);

    CostItem::factory()->create(['amount_minor' => 100, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($empty->id);
    CostItem::factory()->create(['amount_minor' => 200, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($uuid->id);

    $groups = collect(Livewire::test('pages::costs.index')->get('groupedRows'));

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['unlabeled'])->toBeTrue()
        ->and(count($groups[0]['rows']))->toBe(2);
});

test('a group of unknown-priced charges renders a header without a total', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $first = Service::factory()->discovered($account)->create(['name' => 'mystery box']);
    $second = Service::factory()->discovered($account)->create(['name' => 'mystery box']);

    CostItem::factory()->unknownAmount()->create(['observed_at' => now()])->services()->attach($first->id);
    CostItem::factory()->unknownAmount()->create(['observed_at' => now()])->services()->attach($second->id);

    $groups = collect(Livewire::test('pages::costs.index')->get('groupedRows'));

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['label'])->toBe('mystery box')
        ->and($groups[0]['monthly'])->toBe('');

    $this->get(route('costs.index'))
        ->assertOk()
        ->assertSee('mystery box');
});

test('the zero filter removes a group whose only row is free', function () {
    $account = ProviderAccount::factory()->create(['display_name' => 'OVHcloud']);
    $free = Service::factory()->discovered($account)->create(['name' => 'freebie']);
    $priced = Service::factory()->discovered($account)->create(['name' => 'vps-main']);

    CostItem::factory()->create(['amount_minor' => 0, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($free->id);
    CostItem::factory()->create(['amount_minor' => 2000, 'currency' => 'PLN', 'observed_at' => now()])->services()->attach($priced->id);

    // Default hideZeroCost=true: the free-only group vanishes entirely.
    $groups = collect(Livewire::test('pages::costs.index')->get('groupedRows'));

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['label'])->toBe('vps-main');

    $component = Livewire::test('pages::costs.index')->set('hideZeroCost', false);
    $allGroups = collect($component->get('groupedRows'));

    expect($allGroups)->toHaveCount(2)
        ->and($allGroups->pluck('label')->sort()->values()->all())->toBe(['freebie', 'vps-main']);
});
