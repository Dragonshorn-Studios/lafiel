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
    expect($preset->source)->toBe('builtin');
    expect($preset->source_url)->toBeNull();

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
    expect($preset->source)->toBe('manual');
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
    expect($imported->source)->toBe('catalog');
    expect($imported->source_url)->toBe('https://example.com/catalog.json');

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

test('switching the catalog source swaps in that source\'s default url', function () {
    $user = User::factory()->create();
    config(['services.ai_presets_url' => 'https://example.com/lafiel-catalog.json']);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('openImport')
        ->assertSet('importUrl', 'https://example.com/lafiel-catalog.json')
        ->set('importSource', 'china-ai-arbitrage')
        ->assertSet('importUrl', 'https://www.china-ai-arbitrage.xyz/data/plans.json')
        ->set('importSource', 'lafiel')
        ->assertSet('importUrl', 'https://example.com/lafiel-catalog.json');
});

test('plans are imported from the china-ai-arbitrage shape with parsed display prices', function () {
    $user = User::factory()->create();
    $this->seed(SubscriptionPresetSeeder::class);

    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response(catalogFixture('china-ai-arbitrage-plans.json'), 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('openImport')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl');

    // 3 imported (Claude Pro, GLM Coding Plan, ChatGPT Pro), 3 skipped
    // (free tier, placeholder price, entry missing its plan fields).
    $imported = SubscriptionPreset::query()->where('source', 'catalog')->get();

    expect($imported)->toHaveCount(3);

    $claude = $imported->firstWhere('key', 'anthropic:claude-pro');
    expect($claude->vendor)->toBe('Claude')
        ->and($claude->name)->toBe('Claude Pro')
        ->and($claude->amount_minor)->toBe(2000)
        ->and($claude->currency)->toBe('USD')
        ->and($claude->label)->toBe('Claude — Claude Pro ($20)')
        ->and($claude->source_url)->toBe('https://www.china-ai-arbitrage.xyz/data/plans.json')
        ->and($claude->url)->toBe('https://www.anthropic.com/pricing');

    $glm = $imported->firstWhere('key', 'zai:glm-coding-plan');
    expect($glm->amount_minor)->toBe(11800)
        ->and($glm->currency)->toBe('CNY');

    $pro = $imported->firstWhere('key', 'openai:chatgpt-pro');
    expect($pro->amount_minor)->toBe(20000)
        ->and($pro->currency)->toBe('USD')
        ->and($pro->period->value)->toBe('annual');

    // The seeded built-ins are untouched: arbitrage keys (hyphens) do
    // not collide with builtin keys (underscores) — builtin keys are
    // only ever overwritten by a catalog carrying the exact same key
    // (covered by the collision test below).
    expect(SubscriptionPreset::query()->where('key', 'anthropic:claude-pro')->count())->toBe(1)
        ->and(SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail()->source)->toBe('builtin');
});

test('a body without a plans array is rejected as a shape mismatch for the arbitrage source', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response(['models' => [['id' => 'gpt-x']]], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl');

    Http::assertSentCount(1);
    expect(SubscriptionPreset::query()->count())->toBe(0);
});

test('a generated catalog label is truncated to the column bound', function () {
    $user = User::factory()->create();

    $longName = str_repeat('w', 300);
    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response([
            'plans' => [[
                'platform' => ['en' => $longName],
                'plan' => ['en' => $longName],
                'provider_slug' => 'long',
                'plan_slug' => 'plan',
                'price' => '$10',
            ]],
        ], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl');

    $imported = SubscriptionPreset::query()->where('source', 'catalog')->firstOrFail();
    expect(mb_strlen($imported->label))->toBe(255)
        ->and(mb_strlen($imported->vendor))->toBe(255)
        ->and(mb_strlen($imported->name))->toBe(255);
});

test('built-in plans are hidden everywhere once a catalog import exists', function () {
    $user = User::factory()->create();
    $this->seed(SubscriptionPresetSeeder::class);
    $catalogRow = SubscriptionPreset::factory()->fromCatalog()->create(['name' => 'Catalog Plan']);
    $manualRow = SubscriptionPreset::factory()->create(['name' => 'Manual Plan']);
    $builtin = SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail();

    // Costs page: the catalog and manual rows stay pickable, builtins don't.
    $component = Livewire::actingAs($user)->test('pages::costs.index');
    $options = $component->get('presetOptions')->pluck('id');
    expect($options)->toContain($catalogRow->id)
        ->and($options)->toContain($manualRow->id)
        ->and($options)->not->toContain($builtin->id);

    // Plans page: built-ins hidden until revealed.
    $plans = Livewire::actingAs($user)->test('pages::presets.index');
    expect($plans->get('presets')->pluck('id'))->toContain($catalogRow->id)
        ->and($plans->get('presets')->pluck('id'))->toContain($manualRow->id)
        ->and($plans->get('presets')->pluck('id'))->not->toContain($builtin->id);

    $plans->set('showBuiltins', true);
    expect($plans->get('presets')->pluck('id'))->toContain($builtin->id);
});

test('landing on a built-in plan while a catalog is in use warns and stays closed', function () {
    $user = User::factory()->create();
    $this->seed(SubscriptionPresetSeeder::class);
    SubscriptionPreset::factory()->fromCatalog()->create();

    $response = $this->actingAs($user)->get(route('costs.add', SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail()));

    $response->assertOk();

    $data = costsSnapshot($response)['data'];

    expect($data['panelOpen'])->toBeFalse()
        ->and($data['vendor'])->toBe('');
});

test('only built-in plans can be deleted outright, and only with a catalog active', function () {
    $user = User::factory()->create();
    $builtin = SubscriptionPreset::factory()->builtin()->create();
    $manual = SubscriptionPreset::factory()->create();
    SubscriptionPreset::factory()->fromCatalog()->create();

    Livewire::actingAs($user)->test('pages::presets.index')->call('destroy', $manual->id);
    expect(SubscriptionPreset::query()->whereKey($manual->id)->exists())->toBeTrue();

    Livewire::actingAs($user)->test('pages::presets.index')->call('destroy', $builtin->id);
    expect(SubscriptionPreset::query()->whereKey($builtin->id)->exists())->toBeFalse();
});

test('the plans page attributes imported catalog data', function () {
    $user = User::factory()->create();
    SubscriptionPreset::factory()->fromCatalog('https://www.china-ai-arbitrage.xyz/data/plans.json')->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->assertSee('china-ai-arbitrage.xyz')
        ->assertSee('CC BY 4.0');
});

test('archiving every catalog row releases the built-ins back into the pickers', function () {
    $user = User::factory()->create();
    $this->seed(SubscriptionPresetSeeder::class);
    $catalogRow = SubscriptionPreset::factory()->fromCatalog()->create();

    expect(SubscriptionPreset::catalogInUse())->toBeTrue();

    $catalogRow->update(['archived_at' => now()]);

    expect(SubscriptionPreset::catalogInUse())->toBeFalse();

    $options = Livewire::actingAs($user)->test('pages::costs.index')->get('presetOptions')->pluck('id');
    expect($options)->toContain(SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail()->id);
});

test('prices with thousands separators or extra decimals are skipped, not mis-parsed', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response([
            'plans' => [
                ['platform' => ['en' => 'A'], 'plan' => ['en' => 'Comma'], 'provider_slug' => 'a', 'plan_slug' => 'comma', 'price' => '$1,299'],
                ['platform' => ['en' => 'B'], 'plan' => ['en' => 'Thirds'], 'provider_slug' => 'b', 'plan_slug' => 'thirds', 'price' => '$20.999'],
                ['platform' => ['en' => 'C'], 'plan' => ['en' => 'Clean'], 'provider_slug' => 'c', 'plan_slug' => 'clean', 'price' => '$30'],
                ['platform' => ['en' => 'D'], 'plan' => ['en' => 'Overlong key'], 'provider_slug' => str_repeat('k', 300), 'plan_slug' => 'oversized', 'price' => '$30'],
            ],
        ], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl');

    $imported = SubscriptionPreset::query()->where('source', 'catalog')->get();
    expect($imported)->toHaveCount(1)
        ->and($imported->firstOrFail()->key)->toBe('c:clean')
        ->and($imported->firstOrFail()->amount_minor)->toBe(3000);
});

test('editing a built-in plan keeps it built-in', function () {
    $user = User::factory()->create();
    $this->seed(SubscriptionPresetSeeder::class);
    $preset = SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('edit', $preset->id)
        ->set('amount', '25.00')
        ->call('save');

    expect($preset->fresh()->source)->toBe('builtin')
        ->and($preset->fresh()->amount_minor)->toBe(2500);
});

test('built-in plans cannot be deleted while no catalog is active', function () {
    $user = User::factory()->create();
    $builtin = SubscriptionPreset::factory()->builtin()->create();

    Livewire::actingAs($user)->test('pages::presets.index')->call('destroy', $builtin->id);

    expect(SubscriptionPreset::query()->whereKey($builtin->id)->exists())->toBeTrue();
});

test('a lafiel-shape mismatch is rejected instead of reporting zero imports', function () {
    $user = User::factory()->create();
    config(['services.ai_presets_url' => 'https://example.com/lafiel-catalog.json']);

    Http::fake([
        // The china-ai-arbitrage body pasted with the lafiel source
        // selected: no keyed plan entries at the top level.
        'https://example.com/lafiel-catalog.json' => Http::response(['plans' => [['platform' => ['en' => 'Claude']]]], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('openImport')
        ->call('importFromUrl');

    Http::assertSentCount(1);
    expect(SubscriptionPreset::query()->count())->toBe(0);
});

test('a failed import keeps the flyout open with the url for correction', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response(['models' => []], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('openImport')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl')
        ->assertSet('importOpen', true)
        ->assertSet('importUrl', 'https://www.china-ai-arbitrage.xyz/data/plans.json');

    expect(SubscriptionPreset::query()->count())->toBe(0);
});

test('every currency marker the parser documents imports with the right code', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response([
            'plans' => [
                ['platform' => ['en' => 'A'], 'plan' => ['en' => 'CodeUsd'], 'provider_slug' => 'a1', 'plan_slug' => 'code-usd', 'price' => 'USD 3'],
                ['platform' => ['en' => 'B'], 'plan' => ['en' => 'CodeCny'], 'provider_slug' => 'a2', 'plan_slug' => 'code-cny', 'price' => 'CNY 5'],
                ['platform' => ['en' => 'C'], 'plan' => ['en' => 'Fullwidth'], 'provider_slug' => 'a3', 'plan_slug' => 'fullwidth', 'price' => '￥45'],
                ['platform' => ['en' => 'D'], 'plan' => ['en' => 'UsPrefix'], 'provider_slug' => 'a4', 'plan_slug' => 'us-prefix', 'price' => 'US$7'],
                ['platform' => ['en' => 'E'], 'plan' => ['en' => 'Euro'], 'provider_slug' => 'a5', 'plan_slug' => 'euro', 'price' => '€13'],
                ['platform' => ['en' => 'F'], 'plan' => ['en' => 'Pound'], 'provider_slug' => 'a6', 'plan_slug' => 'pound', 'price' => '£9'],
                ['platform' => ['en' => 'G'], 'plan' => ['en' => 'AnnualYr'], 'provider_slug' => 'a7', 'plan_slug' => 'annual-yr', 'price' => '$2399.88/yr'],
            ],
        ], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl');

    $imported = SubscriptionPreset::query()->where('source', 'catalog')->get()->keyBy('key');

    expect($imported)->toHaveCount(7)
        ->and($imported['a1:code-usd']->amount_minor)->toBe(300)
        ->and($imported['a1:code-usd']->currency)->toBe('USD')
        ->and($imported['a2:code-cny']->amount_minor)->toBe(500)
        ->and($imported['a2:code-cny']->currency)->toBe('CNY')
        ->and($imported['a3:fullwidth']->amount_minor)->toBe(4500)
        ->and($imported['a3:fullwidth']->currency)->toBe('CNY')
        ->and($imported['a4:us-prefix']->amount_minor)->toBe(700)
        ->and($imported['a4:us-prefix']->currency)->toBe('USD')
        ->and($imported['a5:euro']->amount_minor)->toBe(1300)
        ->and($imported['a5:euro']->currency)->toBe('EUR')
        ->and($imported['a6:pound']->amount_minor)->toBe(900)
        ->and($imported['a6:pound']->currency)->toBe('GBP')
        ->and($imported['a7:annual-yr']->amount_minor)->toBe(239988)
        ->and($imported['a7:annual-yr']->currency)->toBe('USD')
        ->and($imported['a7:annual-yr']->period->value)->toBe('annual');
});

test('a catalog key that collides with a built-in overwrites it, and re-seeding never resurrects the built-in', function () {
    $user = User::factory()->create();
    $this->seed(SubscriptionPresetSeeder::class);
    expect(SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail()->source)->toBe('builtin');

    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response([
            'plans' => [[
                'platform' => ['en' => 'OpenAI'],
                'plan' => ['en' => 'ChatGPT Plus'],
                'provider_slug' => 'openai',
                'plan_slug' => 'chatgpt_plus',
                'price' => '$20',
            ]],
        ], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl');

    expect(SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->count())->toBe(1);

    $row = SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->firstOrFail();
    expect($row->source)->toBe('catalog')
        ->and($row->vendor)->toBe('OpenAI');

    // Re-seeding fills only missing keys; the catalog-owned row stays.
    $this->seed(SubscriptionPresetSeeder::class);

    expect(SubscriptionPreset::query()->where('key', 'openai:chatgpt_plus')->count())->toBe(1)
        ->and($row->fresh()->source)->toBe('catalog');
});

test('a catalog url longer than the column bound is rejected before any fetch', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('openImport')
        ->set('importUrl', 'https://example.com/'.str_repeat('a', 2100))
        ->call('importFromUrl')
        ->assertSet('importOpen', true);

    Http::assertNothingSent();

    expect(SubscriptionPreset::query()->count())->toBe(0);
});

test('attribution names the publisher license only for known catalog hosts', function () {
    $user = User::factory()->create();
    SubscriptionPreset::factory()->fromCatalog()->create();

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->assertSee('example.com')
        ->assertSee('licensed by its publisher');
});

test('a mid-import database failure rolls back, is reported, and keeps the flyout open', function () {
    $user = User::factory()->create();
    Exceptions::fake();

    $seen = 0;
    SubscriptionPreset::creating(function () use (&$seen): void {
        $seen++;
        if ($seen === 2) {
            throw new RuntimeException('Simulated mid-import database failure.');
        }
    });

    Http::fake([
        'https://www.china-ai-arbitrage.xyz/*' => Http::response([
            'plans' => [
                ['platform' => ['en' => 'A'], 'plan' => ['en' => 'First'], 'provider_slug' => 'a', 'plan_slug' => 'first', 'price' => '$1'],
                ['platform' => ['en' => 'B'], 'plan' => ['en' => 'Second'], 'provider_slug' => 'b', 'plan_slug' => 'second', 'price' => '$2'],
            ],
        ], 200),
    ]);

    Livewire::actingAs($user)
        ->test('pages::presets.index')
        ->call('openImport')
        ->set('importSource', 'china-ai-arbitrage')
        ->call('importFromUrl')
        ->assertSet('importOpen', true);

    expect(SubscriptionPreset::query()->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});
