<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Inventory\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function costsPage(): Testable
{
    return Livewire::test('pages::costs.index');
}

function validCostInput(array $overrides = []): array
{
    return [
        'vendor' => 'OpenAI',
        'name' => 'ChatGPT Plus',
        'category' => 'ai',
        'unknown_amount' => false,
        'amount' => '86.99',
        'currency' => 'PLN',
        'period' => 'monthly',
        'valid_from' => '2026-09-01',
        'valid_to' => null,
        'renews_at' => '2026-10-01',
        'auto_renew' => true,
        'url' => 'https://example.com',
        'notes' => 'Team seat',
        'covers_service_id' => null,
        ...$overrides,
    ];
}

test('guests cannot reach the costs pages', function () {
    $this->app->make('auth')->guard('web')->logout();

    $this->get(route('costs.index'))->assertRedirect(route('login'));
    $this->get(route('costs.history'))->assertRedirect(route('login'));
    $this->get(route('costs.renewals'))->assertRedirect(route('login'));
});

test('a manual cost reaches the list and the projection', function () {
    costsPage()
        ->set('vendor', 'OpenAI')
        ->set('name', 'ChatGPT Plus')
        ->set('category', 'ai')
        ->set('amount', '86.99')
        ->set('currency', 'PLN')
        ->set('period', 'monthly')
        ->set('validFrom', '2026-08-01')
        ->set('renewsAt', '2026-10-01')
        ->set('autoRenew', true)
        ->call('save')
        ->assertHasNoErrors();

    $service = Service::query()->whereNull('provider_account_id')->sole();

    expect($service->vendor)->toEqual('OpenAI');
    expect($service->costItems)->toHaveCount(1);

    $costItem = $service->costItems->sole();

    expect($costItem->amount_minor)->toEqual(8699);
    expect($costItem->currency)->toEqual('PLN');
    expect($costItem->source_kind->value)->toEqual('manual');
    expect($costItem->evidence_state->value)->toEqual('manual');

    expect(Renewal::query()->count())->toEqual(1);

    // The Overview sees the same cost through the shared projection.
    $overview = Livewire::test('pages::overview.totals');

    expect($overview->get('summary')['monthly'])->toEqual('86.99 PLN/mo');
});

test('an unknown amount is accepted and counted, never summed', function () {
    costsPage()
        ->set('name', 'Mystery hosting')
        ->set('category', 'hosting')
        ->set('unknownAmount', true)
        ->set('currency', 'PLN')
        ->set('period', 'monthly')
        ->set('validFrom', '2026-08-01')
        ->call('save')
        ->assertHasNoErrors();

    $item = CostItem::query()->sole();

    expect($item->amount_state->value)->toEqual('unknown');

    $overview = Livewire::test('pages::overview.totals');

    expect($overview->get('summary')['monthly'])->toEqual('0.00 PLN/mo + 1 unknown');
});

test('a price change keeps history and switches the projection', function () {
    costsPage()
        ->set('name', 'ChatGPT Plus')
        ->set('category', 'ai')
        ->set('amount', '86.99')
        ->set('currency', 'PLN')
        ->set('period', 'monthly')
        ->set('validFrom', '2026-08-01')
        ->call('save');

    $service = Service::query()->whereNull('provider_account_id')->sole();

    costsPage()
        ->call('edit', $service->id)
        ->set('amount', '97.00')
        ->call('save')
        ->assertHasNoErrors();

    $items = CostItem::query()->where('logical_charge_key', sprintf('manual:service:%d', $service->id))->get();

    // The old price is closed, not rewritten; both remain in history.
    expect($items)->toHaveCount(2);

    $closed = $items->firstWhere('valid_to', '!==', null);
    $open = $items->firstWhere('valid_to', '===', null);

    expect($closed->amount_minor)->toEqual(8699);
    expect($closed->valid_to->format('Y-m-d'))->toEqual('2026-09-09');
    expect($open->amount_minor)->toEqual(9700);
    expect($open->logical_charge_key)->toEqual($closed->logical_charge_key);

    // Today projects the new price; last month projected the old one.
    $today = app(CostProjector::class)->project(new CarbonImmutable('2026-09-10'));
    expect($today->forCurrency('PLN')->monthlyMinor)->toEqual(9700);

    $lastMonth = app(CostProjector::class)->project(new CarbonImmutable('2026-08-15'));
    expect($lastMonth->forCurrency('PLN')->monthlyMinor)->toEqual(8699);
});

test('an ended item leaves future projections and stays in history', function () {
    costsPage()
        ->set('name', 'Old domain')
        ->set('category', 'domain')
        ->set('amount', '99.00')
        ->set('currency', 'PLN')
        ->set('period', 'annual')
        ->set('validFrom', '2026-08-01')
        ->call('save');

    $service = Service::query()->whereNull('provider_account_id')->sole();

    costsPage()->call('end', $service->id)->assertHasNoErrors();

    // The end date is the last charged day; from tomorrow it is gone.
    $today = app(CostProjector::class)->project(new CarbonImmutable('2026-09-10'));
    expect($today->forCurrency('PLN')->monthlyMinor)->toEqual(825);

    $tomorrow = app(CostProjector::class)->project(new CarbonImmutable('2026-09-11'));
    expect($tomorrow->forCurrency('PLN'))->toBeNull();

    $beforeEnd = app(CostProjector::class)->project(new CarbonImmutable('2026-08-15'));
    expect($beforeEnd->forCurrency('PLN')->monthlyMinor)->toEqual(825);

    // History keeps the ended item.
    Livewire::test('pages::costs.history')
        ->assertOk()
        ->assertSee('Old domain');
});

test('a manual cost can overlay an existing provider service', function () {
    $discovered = Service::factory()->discovered()->create();

    costsPage()
        ->set('name', 'Manual price for VPS')
        ->set('category', 'compute')
        ->set('amount', '42.00')
        ->set('currency', 'PLN')
        ->set('period', 'monthly')
        ->set('validFrom', '2026-08-01')
        ->set('coversServiceId', $discovered->id)
        ->call('save')
        ->assertHasNoErrors();

    // No new service was created; the cost attaches to the discovered one.
    expect(Service::query()->count())->toEqual(1);
    expect($discovered->costItems()->count())->toEqual(1);

    // The overlay counts once in the projection.
    $overview = Livewire::test('pages::overview.totals');
    expect($overview->get('summary')['monthly'])->toEqual('42.00 PLN/mo');
});

test('renewals of active costs are listed', function () {
    costsPage()
        ->set('name', 'ChatGPT Plus')
        ->set('category', 'ai')
        ->set('amount', '86.99')
        ->set('currency', 'PLN')
        ->set('period', 'monthly')
        ->set('validFrom', '2026-08-01')
        ->set('renewsAt', '2026-10-01')
        ->call('save');

    Livewire::test('pages::costs.renewals')
        ->assertOk()
        ->assertSee('ChatGPT Plus')
        ->assertSee('2026-10-01');
});

test('cost input is validated', function () {
    costsPage()
        ->set('name', '')
        ->set('category', '')
        ->set('unknownAmount', false)
        ->set('amount', '')
        ->set('validFrom', 'not-a-date')
        ->call('save')
        ->assertHasErrors(['name', 'category', 'amount', 'valid_from']);

    expect(CostItem::query()->count())->toEqual(0);
});
