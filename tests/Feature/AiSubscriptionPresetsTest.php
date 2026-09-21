<?php

use App\Domain\Costs\AiSubscriptionPresets;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    AiSubscriptionPresets::clearCache();
});

test('ai subscription presets returns valid configuration array', function () {
    $all = AiSubscriptionPresets::all();

    expect($all)->not->toBeEmpty();
    expect($all)->toHaveKey('openai:chatgpt_plus');

    $preset = AiSubscriptionPresets::find('openai:chatgpt_plus');
    expect($preset)->not->toBeNull();
    expect($preset['vendor'])->toEqual('OpenAI');
    expect($preset['name'])->toEqual('ChatGPT Plus');
    expect($preset['amount'])->toEqual('20.00');
    expect($preset['currency'])->toEqual('USD');
});

test('selecting an ai subscription preset populates the form and saves the cost item', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::costs.index')
        ->set('aiPresetKey', 'openai:chatgpt_plus');

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
    expect($service->vendor)->toEqual('OpenAI');
    expect($service->category)->toEqual('ai');

    $costItem = CostItem::query()->whereHas('services', fn ($q) => $q->where('services.id', $service->id))->first();
    expect($costItem)->not->toBeNull();
    expect($costItem->amount_minor)->toEqual(2000);
    expect($costItem->currency)->toEqual('USD');
});

test('ai subscription presets dynamically fetches remote url when configured', function () {
    config(['services.ai_presets_url' => 'https://example.com/ai_presets.json']);

    Http::fake([
        'https://example.com/ai_presets.json' => Http::response([
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
        ], 200),
    ]);

    $all = AiSubscriptionPresets::all();

    expect($all)->toHaveKey('custom:super_ai');
    expect($all['custom:super_ai']['amount'])->toEqual('50.00');
    expect($all)->toHaveKey('openai:chatgpt_plus');
});

test('ai subscription presets falls back to default when remote fetch fails', function () {
    config(['services.ai_presets_url' => 'https://example.com/ai_presets.json']);

    Http::fake([
        'https://example.com/ai_presets.json' => Http::response(null, 500),
    ]);

    $all = AiSubscriptionPresets::all();

    expect($all)->toHaveKey('openai:chatgpt_plus');
    expect($all)->not->toHaveKey('custom:super_ai');
});
