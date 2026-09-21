<?php

use App\Domain\Costs\AiSubscriptionPresets;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use App\Models\User;
use Livewire\Livewire;

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
