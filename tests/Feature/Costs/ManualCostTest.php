<?php

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Actions\EndManualCost;
use App\Domain\Costs\Actions\UpdateManualCost;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Costs\Projection\ProjectionResult;
use App\Domain\Inventory\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
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

function projectOnDate(string $date): ProjectionResult
{
    return app(CostProjector::class)->project(new CarbonImmutable($date));
}

function createCost(array $overrides = []): CostItem
{
    return app(CreateManualCost::class)->create([
        'vendor' => 'OpenAI',
        'name' => 'ChatGPT Plus',
        'category' => 'ai',
        'unknown_amount' => false,
        'amount' => '86.99',
        'currency' => 'PLN',
        'period' => 'monthly',
        'valid_from' => '2026-09-01',
        'valid_to' => null,
        'renews_at' => null,
        'auto_renew' => false,
        'url' => null,
        'notes' => null,
        'covers_service_id' => null,
        ...$overrides,
    ]);
}

test('guests cannot reach the costs pages', function () {
    auth()->logout();

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
    $overview = Livewire::test('pages::overview.index');

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

    $overview = Livewire::test('pages::overview.index');

    expect($overview->get('summary')['monthly'])->toEqual('0.00 PLN/mo + 1 unknown');
});

test('a price change keeps history and switches the projection', function () {
    $item = createCost(['valid_from' => '2026-08-01']);

    costsPage()
        ->call('edit', $item->id)
        ->set('amount', '97.00')
        ->call('save')
        ->assertHasNoErrors();

    $items = CostItem::query()->where('logical_charge_key', $item->logical_charge_key)->get();

    // The old price is closed, not rewritten; both remain in history.
    expect($items)->toHaveCount(2);

    $closed = $items->firstWhere('valid_to', '!==', null);
    $open = $items->firstWhere('valid_to', '===', null);

    expect($closed->amount_minor)->toEqual(8699);
    expect($closed->valid_to->format('Y-m-d'))->toEqual('2026-09-09');
    expect($open->amount_minor)->toEqual(9700);
    expect($open->logical_charge_key)->toEqual($closed->logical_charge_key);

    // Today projects the new price; last month projected the old one.
    expect(projectOnDate('2026-09-10')->forCurrency('PLN')->monthlyMinor)->toEqual(9700);
    expect(projectOnDate('2026-08-15')->forCurrency('PLN')->monthlyMinor)->toEqual(8699);
});

test('a price change on the creation day does not collide on identity', function () {
    $item = createCost(['valid_from' => '2026-09-10']);

    costsPage()
        ->call('edit', $item->id)
        ->set('amount', '97.00')
        ->call('save')
        ->assertHasNoErrors();

    $items = CostItem::query()->where('logical_charge_key', $item->logical_charge_key)->get();

    expect($items)->toHaveCount(2);
    expect($items->pluck('identity_key')->unique())->toHaveCount(2);

    expect(projectOnDate('2026-09-10')->forCurrency('PLN')->monthlyMinor)->toEqual(9700);
});

test('two price changes on the same day keep every version', function () {
    $item = createCost(['valid_from' => '2026-09-10']);

    costsPage()->call('edit', $item->id)->set('amount', '97.00')->call('save');
    $openId = CostItem::query()->where('logical_charge_key', $item->logical_charge_key)->whereNull('valid_to')->sole()->id;

    costsPage()->call('edit', $openId)->set('amount', '99.00')->call('save')->assertHasNoErrors();

    $versions = CostItem::query()->where('logical_charge_key', $item->logical_charge_key)->orderBy('id')->get();

    expect($versions)->toHaveCount(3);
    expect($versions->pluck('amount_minor')->toArray())->toEqual([8699, 9700, 9900]);
    expect(projectOnDate('2026-09-10')->forCurrency('PLN')->monthlyMinor)->toEqual(9900);
});

test('independent charges on one service are each counted once', function () {
    $discovered = Service::factory()->discovered()->create();

    createCost(['name' => 'VPS base fee', 'amount' => '42.00', 'covers_service_id' => $discovered->id]);
    createCost(['name' => 'VPS backup add-on', 'amount' => '10.00', 'covers_service_id' => $discovered->id]);

    // No new services, both charges coexist on the covered service.
    expect(Service::query()->count())->toEqual(1);
    expect($discovered->costItems()->count())->toEqual(2);

    // Each charge joins the known sum exactly once.
    expect(projectOnDate('2026-09-10')->forCurrency('PLN')->monthlyMinor)->toEqual(5200);
});

test('a renewal added during a price change lands on the new version', function () {
    $item = createCost(['valid_from' => '2026-08-01']);

    costsPage()
        ->call('edit', $item->id)
        ->set('amount', '97.00')
        ->set('renewsAt', '2026-10-01')
        ->call('save')
        ->assertHasNoErrors();

    $open = CostItem::query()->where('logical_charge_key', $item->logical_charge_key)->whereNull('valid_to')->sole();

    $renewal = Renewal::query()->sole();

    expect($renewal->cost_item_id)->toEqual($open->id);
    expect($renewal->renews_at->format('Y-m-d'))->toEqual('2026-10-01');
});

test('an existing renewal follows the charge to the new version', function () {
    $item = createCost(['valid_from' => '2026-08-01', 'renews_at' => '2026-10-01', 'auto_renew' => true]);

    costsPage()
        ->call('edit', $item->id)
        ->set('amount', '97.00')
        ->set('renewsAt', '2026-11-01')
        ->call('save')
        ->assertHasNoErrors();

    $open = CostItem::query()->where('logical_charge_key', $item->logical_charge_key)->whereNull('valid_to')->sole();
    $renewal = Renewal::query()->sole();

    expect($renewal->cost_item_id)->toEqual($open->id);
    expect($renewal->renews_at->format('Y-m-d'))->toEqual('2026-11-01');
    expect($renewal->auto_renew)->toBeTrue();

    // The renewals page lists the open version's renewal.
    Livewire::test('pages::costs.renewals')
        ->assertOk()
        ->assertSee('2026-11-01');
});

test('editing without a price change updates the service in place', function () {
    $item = createCost(['valid_from' => '2026-08-01']);

    costsPage()
        ->call('edit', $item->id)
        ->set('name', 'ChatGPT Team')
        ->set('notes', 'Renamed plan')
        ->call('save')
        ->assertHasNoErrors();

    // No new version: the price fact is untouched.
    expect(CostItem::query()->count())->toEqual(1);

    $item->refresh();
    $service = $item->services->first();

    expect($service->name)->toEqual('ChatGPT Team');
    expect($item->notes)->toEqual('Renamed plan');
});

test('an ended item leaves future projections and stays in history', function () {
    $item = createCost(['valid_from' => '2026-08-01', 'period' => 'annual', 'amount' => '99.00']);

    costsPage()->call('end', $item->id)->assertHasNoErrors();

    // The end date is the last charged day; from tomorrow it is gone.
    expect(projectOnDate('2026-09-10')->forCurrency('PLN')->monthlyMinor)->toEqual(825);
    expect(projectOnDate('2026-09-11')->forCurrency('PLN'))->toBeNull();
    expect(projectOnDate('2026-08-15')->forCurrency('PLN')->monthlyMinor)->toEqual(825);

    // History keeps the ended item.
    Livewire::test('pages::costs.history')
        ->assertOk()
        ->assertSee('ChatGPT Plus');
});

test('updating an already ended charge is refused, not resurrected', function () {
    $item = createCost(['valid_from' => '2026-08-01']);

    app(EndManualCost::class)->end($item);

    app(UpdateManualCost::class)->update($item, [
        'vendor' => 'OpenAI',
        'name' => 'ChatGPT Plus',
        'category' => 'ai',
        'unknown_amount' => false,
        'amount' => '97.00',
        'currency' => 'PLN',
        'period' => 'monthly',
        'valid_from' => '2026-08-01',
        'valid_to' => null,
        'renews_at' => null,
        'auto_renew' => false,
        'url' => null,
        'notes' => null,
        'price_changed' => true,
    ]);
})->throws(ValidationException::class);

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
    $overview = Livewire::test('pages::overview.index');
    expect($overview->get('summary')['monthly'])->toEqual('42.00 PLN/mo');
});

test('renewals of active costs are listed', function () {
    createCost(['renews_at' => '2026-10-01']);

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

test('a non-ISO currency string is a validation error, not a crash', function () {
    costsPage()
        ->set('name', 'ChatGPT Plus')
        ->set('category', 'ai')
        ->set('amount', '86.99')
        ->set('currency', 'ab$')
        ->set('period', 'monthly')
        ->set('validFrom', '2026-08-01')
        ->call('save')
        ->assertHasErrors(['currency']);

    expect(CostItem::query()->count())->toEqual(0);
});
