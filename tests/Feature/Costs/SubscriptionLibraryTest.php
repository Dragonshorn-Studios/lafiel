<?php

use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\SubscriptionPreset;
use App\Domain\Inventory\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SubscriptionPresetSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-27 12:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The Livewire snapshot embedded in the rendered page — the state the
 * browser would hydrate, reached through the real route. The layout
 * embeds shell components too, so pick the snapshot belonging to the
 * page component (it owns the cost form's properties).
 *
 * @return array<string, mixed>
 */
function costsSnapshot(TestResponse $response): array
{
    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $response->getContent(), $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $encoded) {
        $snapshot = json_decode(html_entity_decode($encoded), true);

        if (is_array($snapshot) && isset($snapshot['data']['hideZeroCost'])) {
            return $snapshot;
        }
    }

    fail('The costs page snapshot was not found in the rendered HTML.');
}

test('guests are redirected to login from the plans page', function () {
    $this->get(route('presets.index'))->assertRedirect(route('login'));
});

test('a negative plan price is rejected by the database', function () {
    // Insert below Eloquent: the form rule rejects negatives first,
    // the CHECK trigger is the backstop for raw writers.
    DB::table('subscription_presets')->insert([
        'key' => 'raw:negative',
        'label' => 'Negative',
        'vendor' => 'Raw',
        'name' => 'Negative',
        'category' => 'ai',
        'amount_minor' => -100,
        'currency' => 'USD',
        'period' => 'monthly',
        'auto_renew' => true,
    ]);
})->throws(QueryException::class);

test('the seeder seeds the built-in catalog idempotently without clobbering edits', function () {
    $this->seed(SubscriptionPresetSeeder::class);

    expect(SubscriptionPreset::query()->count())->toBe(22);

    $preset = SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail();
    expect($preset->vendor)->toBe('OpenAI');
    expect($preset->name)->toBe('ChatGPT Plus');
    expect($preset->amount_minor)->toBe(2000);
    expect($preset->currency)->toBe('USD');
    expect($preset->period)->toBe(Period::Monthly);
    expect($preset->auto_renew)->toBeTrue();
    expect($preset->label)->toBe('OpenAI — ChatGPT Plus ($20.00/mo)');

    $seat = SubscriptionPreset::query()->where('key', 'openai:chatgpt_team')->firstOrFail();
    expect($seat->label)->toBe('OpenAI — ChatGPT Team ($25.00/mo/seat)');

    $preset->update(['amount_minor' => 1234]);

    $this->seed(SubscriptionPresetSeeder::class);

    expect(SubscriptionPreset::query()->count())->toBe(22);
    expect($preset->fresh()->amount_minor)->toBe(1234);
});

test('a plan can be added with key and label derived from vendor and name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('add')
        ->set('vendor', 'Mikr.us')
        ->set('name', 'LUFFA 30')
        ->set('category', 'compute')
        ->set('amount', '4.00')
        ->call('save');

    $preset = SubscriptionPreset::query()->where('name', 'LUFFA 30')->firstOrFail();
    expect($preset->key)->toBe('mikrus:luffa-30');
    expect($preset->label)->toBe('Mikr.us — LUFFA 30 ($4.00/mo)');
    expect($preset->amount_minor)->toBe(400);
    expect($preset->currency)->toBe('USD');
    expect($preset->archived_at)->toBeNull();
});

test('a plan cannot claim another plan\'s key', function () {
    $user = User::factory()->create();
    SubscriptionPreset::factory()->create(['key' => 'taken:key']);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('add')
        ->set('key', ' taken:key ')
        ->set('vendor', 'X')
        ->set('name', 'Y')
        ->set('category', 'saas')
        ->set('amount', '1.00')
        ->call('save')
        ->assertHasErrors('key');

    expect(SubscriptionPreset::query()->count())->toBe(1);
});

test('an absurd amount surfaces a field error instead of a server error', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('add')
        ->set('vendor', 'X')
        ->set('name', 'Y')
        ->set('category', 'saas')
        ->set('amount', '999999999999999999999.00')
        ->call('save')
        ->assertHasErrors('amount');

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('add')
        ->set('vendor', 'X')
        ->set('name', 'Y')
        ->set('category', 'saas')
        ->set('amount', '-5.00')
        ->call('save')
        ->assertHasErrors('amount');

    expect(SubscriptionPreset::query()->count())->toBe(0);
});

test('a blank label for a non-USD plan carries the currency code', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('add')
        ->set('vendor', 'Mikr.us')
        ->set('name', 'LUFFA 30')
        ->set('category', 'compute')
        ->set('amount', '4.00')
        ->set('currency', 'pln')
        ->call('save');

    $preset = SubscriptionPreset::query()->where('name', 'LUFFA 30')->firstOrFail();
    expect($preset->label)->toBe('Mikr.us — LUFFA 30 (4.00 PLN/mo)');
    expect($preset->currency)->toBe('PLN');
});

test('a plan can be edited, archived, and restored', function () {
    $user = User::factory()->create();
    $preset = SubscriptionPreset::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('edit', $preset->id)
        ->assertSet('vendor', $preset->vendor)
        ->assertSet('name', $preset->name)
        ->assertSet('amount', $preset->amount_minor / 100)
        ->set('amount', '9.99')
        ->call('save');

    expect($preset->fresh()->amount_minor)->toBe(999);

    Livewire::actingAs($user)->test('pages::presets.index')->call('archive', $preset->id);
    expect($preset->fresh()->archived_at)->not->toBeNull();

    Livewire::actingAs($user)->test('pages::presets.index')->call('restore', $preset->id);
    expect($preset->fresh()->archived_at)->toBeNull();
});

test('plans are imported from the configured catalog url and malformed entries are skipped', function () {
    $user = User::factory()->create();
    config(['services.ai_presets_url' => 'https://example.com/catalog.json']);
    SubscriptionPreset::factory()->create(['key' => 'custom:super_ai', 'label' => 'Old', 'amount_minor' => 1]);

    Http::fake([
        'https://example.com/catalog.json' => Http::response([
            'custom:super_ai' => [
                'key' => 'custom:super_ai',
                'label' => 'Custom Provider — Super AI ($50/mo)',
                'vendor' => 'Custom Provider',
                'name' => 'Super AI',
                'category' => 'ai',
                'amount' => '50.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://custom.ai',
            ],
            'custom:unlabeled' => ['key' => 'custom:unlabeled', 'amount' => '10.00'],
            'custom:bad_amount' => ['key' => 'custom:bad_amount', 'label' => 'Bad', 'amount' => 'abc'],
            'custom:bad_period' => ['key' => 'custom:bad_period', 'label' => 'Bad', 'amount' => '1.00', 'period' => 'weekly'],
            'custom:new_plan' => [
                'key' => 'custom:new_plan',
                'label' => 'Custom Provider — New Plan ($7/mo)',
                'vendor' => 'Custom Provider',
                'name' => 'New Plan',
                'amount' => '7.00',
            ],
        ], 200),
    ]);

    Livewire::actingAs($user)->test('pages::presets.index')->call('importFromUrl');

    expect(SubscriptionPreset::query()->count())->toBe(2);

    $imported = SubscriptionPreset::query()->where('key', 'custom:super_ai')->firstOrFail();
    expect($imported->amount_minor)->toBe(5000);
    expect($imported->currency)->toBe('USD');
    expect($imported->url)->toBe('https://custom.ai');
    expect($imported->label)->toBe('Custom Provider — Super AI ($50/mo)');

    $created = SubscriptionPreset::query()->where('key', 'custom:new_plan')->firstOrFail();
    expect($created->amount_minor)->toBe(700);
    expect($created->vendor)->toBe('Custom Provider');
    expect($created->name)->toBe('New Plan');
});

test('a failed catalog import creates nothing and stays on the page', function () {
    $user = User::factory()->create();
    config(['services.ai_presets_url' => 'https://example.com/catalog.json']);

    Http::fake([
        'https://example.com/catalog.json' => Http::response(null, 500),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('importFromUrl');

    expect(SubscriptionPreset::query()->count())->toBe(0);
});

test('an unreachable catalog is reported and creates nothing', function () {
    $user = User::factory()->create();
    config(['services.ai_presets_url' => 'https://example.com/catalog.json']);

    Exceptions::fake();

    Http::fake([
        'https://example.com/catalog.json' => Http::failedConnection('cURL error 28: Connection timed out'),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('importFromUrl');

    expect(SubscriptionPreset::query()->count())->toBe(0);
    Exceptions::assertReported(ConnectionException::class);
});

test('an import without a configured url creates nothing', function () {
    $user = User::factory()->create();
    config(['services.ai_presets_url' => null]);

    Livewire::actingAs($user)->test('pages::presets.index')->call('importFromUrl');

    expect(SubscriptionPreset::query()->count())->toBe(0);
});

test('selecting a plan populates the cost form and saves the cost item', function () {
    $user = User::factory()->create();
    $preset = SubscriptionPreset::factory()->create([
        'key' => 'openai:chatgpt_plus',
        'label' => 'OpenAI — ChatGPT Plus ($20/mo)',
        'vendor' => 'OpenAI',
        'name' => 'ChatGPT Plus',
        'category' => 'ai',
        'amount_minor' => 2000,
        'currency' => 'USD',
        'period' => 'monthly',
        'auto_renew' => true,
        'url' => 'https://chatgpt.com',
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::costs.index')
        ->set('presetId', $preset->id);

    $component->assertSet('vendor', 'OpenAI')
        ->assertSet('name', 'ChatGPT Plus')
        ->assertSet('category', 'ai')
        ->assertSet('amount', '20.00')
        ->assertSet('currency', 'USD')
        ->assertSet('period', 'monthly')
        ->assertSet('autoRenew', true)
        ->assertSet('url', 'https://chatgpt.com');

    $component->call('save');

    $service = Service::query()->where('name', 'ChatGPT Plus')->first();
    expect($service)->not->toBeNull();
    expect($service->vendor)->toBe('OpenAI');
    expect($service->category)->toBe('ai');

    $costItem = CostItem::query()->whereHas('services', fn ($q) => $q->where('services.id', $service->id))->first();
    expect($costItem)->not->toBeNull();
    expect($costItem->amount_minor)->toBe(2000);
    expect($costItem->currency)->toBe('USD');
});

test('add as recurring cost lands on the costs page with the flyout prefilled', function () {
    $user = User::factory()->create();
    $preset = SubscriptionPreset::factory()->create([
        'vendor' => 'OpenAI',
        'name' => 'ChatGPT Plus',
        'amount_minor' => 1234,
    ]);

    $response = $this->actingAs($user)->get(route('costs.add', $preset->id));
    $response->assertOk();

    $data = costsSnapshot($response)['data'];

    expect($data['panelOpen'])->toBeTrue();
    expect($data['vendor'])->toBe('OpenAI');
    expect($data['name'])->toBe('ChatGPT Plus');
    expect($data['amount'])->toBe('12.34');
});

test('an archived plan does not open the add-cost flyout on landing', function () {
    $user = User::factory()->create();
    $preset = SubscriptionPreset::factory()->archived()->create(['vendor' => 'Ghost Vendor']);

    $response = $this->actingAs($user)->get(route('costs.add', $preset->id));
    $response->assertOk();

    $data = costsSnapshot($response)['data'];

    expect($data['panelOpen'])->toBeFalse();
    expect($data['vendor'])->toBe('');
});

test('archived plans are not offered as presets on the costs page', function () {
    $user = User::factory()->create();
    $active = SubscriptionPreset::factory()->create(['name' => 'Active Plan']);
    $archived = SubscriptionPreset::factory()->archived()->create(['name' => 'Archived Plan']);

    $component = Livewire::actingAs($user)->test('pages::costs.index');

    $options = $component->get('presetOptions')->pluck('id');
    expect($options)->toContain($active->id);
    expect($options)->not->toContain($archived->id);
});

test('add as recurring cost redirects an active plan to the costs page', function () {
    $user = User::factory()->create();
    $preset = SubscriptionPreset::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('addAsCost', $preset->id)
        ->assertRedirect(route('costs.add', ['preset' => $preset->id]));
});

test('add as recurring cost refuses an archived plan', function () {
    $user = User::factory()->create();
    $preset = SubscriptionPreset::factory()->archived()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('addAsCost', $preset->id)
        ->assertNoRedirect();
});

test('a stale preset selection fails closed instead of prefilling', function () {
    $user = User::factory()->create();
    $archived = SubscriptionPreset::factory()->archived()->create(['vendor' => 'Ghost Vendor']);

    Livewire::actingAs($user)
        ->test('pages::costs.index')
        ->set('presetId', $archived->id)
        ->assertSet('presetId', null)
        ->assertSet('vendor', '');
});

test('guests are redirected to login from the add-cost landing route', function () {
    $preset = SubscriptionPreset::factory()->create();

    $this->get(route('costs.add', $preset->id))->assertRedirect(route('login'));
});

test('a non-numeric preset id on the landing route is a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/costs/add/abc')->assertNotFound();
});
