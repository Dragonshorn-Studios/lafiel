<?php

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Providers\Dtos\InventoryBatch;
use App\Domain\Providers\Dtos\InventoryItem;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\OpenRouter\BuildOpenRouterApi;
use App\Domain\Providers\OpenRouter\OpenRouterApi;
use App\Domain\Providers\OpenRouter\OpenRouterCredentialSchema;
use App\Domain\Providers\OpenRouter\OpenRouterProviderAdapter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('openrouter credential schema validates rules and formats payload', function () {
    expect(OpenRouterCredentialSchema::label())->toEqual('OpenRouter');
    expect(OpenRouterCredentialSchema::summary([]))->toEqual('API key');

    $validated = ['api_key' => '  sk-or-v1-123456  '];
    $payload = OpenRouterCredentialSchema::payload($validated);

    expect($payload)->toEqual(['api_key' => 'sk-or-v1-123456']);
});

test('openrouter adapter validates credentials', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'openrouter']);

    $api = Mockery::mock(OpenRouterApi::class);
    $api->shouldReceive('get')->with('/auth/key')->andReturn(['data' => ['label' => 'Test Key']]);

    $builder = Mockery::mock(BuildOpenRouterApi::class);
    $builder->shouldReceive('build')->andReturn($api);

    $adapter = new OpenRouterProviderAdapter($builder);
    $context = new SyncContext($account, ['api_key' => 'sk-or-v1-test'], new CarbonImmutable('2026-09-15'));

    $check = $adapter->validateCredentials($context);
    expect($check->valid)->toBeTrue();
});

test('openrouter adapter handles invalid credentials', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'openrouter']);

    $builder = Mockery::mock(BuildOpenRouterApi::class);
    $builder->shouldReceive('build')->andThrow(new InvalidCredentialsException('Key rejected.'));

    $adapter = new OpenRouterProviderAdapter($builder);
    $context = new SyncContext($account, ['api_key' => 'sk-or-v1-invalid'], new CarbonImmutable('2026-09-15'));

    $check = $adapter->validateCredentials($context);
    expect($check->valid)->toBeFalse();
    expect($check->warning)->toEqual('Key rejected.');
});

test('openrouter adapter fetches inventory', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'openrouter']);

    $api = Mockery::mock(OpenRouterApi::class);
    $api->shouldReceive('get')->with('/auth/key')->andReturn([
        'data' => [
            'label' => 'Production Key',
            'limit' => 100.0,
            'usage' => 12.34,
        ],
    ]);

    $builder = Mockery::mock(BuildOpenRouterApi::class);
    $builder->shouldReceive('build')->andReturn($api);

    $adapter = new OpenRouterProviderAdapter($builder);
    $context = new SyncContext($account, ['api_key' => 'sk-or-v1-test'], new CarbonImmutable('2026-09-15'));

    $batch = $adapter->fetchInventory($context);

    expect($batch->completeness)->toEqual(BatchCompleteness::Complete);
    expect($batch->items)->toHaveCount(1);

    /** @var InventoryItem $item */
    $item = $batch->items[0];
    expect($item->externalId)->toEqual('openrouter:key');
    expect($item->category)->toEqual('saas');
    expect($item->name)->toEqual('OpenRouter (Production Key)');
});

test('openrouter adapter fetches cost facts from credits and key usage', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'openrouter']);

    $api = Mockery::mock(OpenRouterApi::class);
    $api->shouldReceive('get')->with('/credits')->andReturn([
        'data' => [
            'total_credits' => 50.00,
            'total_usage' => 12.34,
        ],
    ]);

    $builder = Mockery::mock(BuildOpenRouterApi::class);
    $builder->shouldReceive('build')->andReturn($api);

    $adapter = new OpenRouterProviderAdapter($builder);
    $context = new SyncContext($account, ['api_key' => 'sk-or-v1-test'], new CarbonImmutable('2026-09-15'));

    $inventory = new InventoryBatch(
        completeness: BatchCompleteness::Complete,
        observedAt: $context->now,
        sourceRef: 'openrouter:/auth/key',
        items: [
            new InventoryItem('openrouter:key', 'saas', 'OpenRouter Key', 'api_key'),
        ],
    );

    $batch = $adapter->fetchCostFacts($context, $inventory);

    expect($batch->completeness)->toEqual(BatchCompleteness::Complete);
    expect($batch->facts)->toHaveCount(1);

    $fact = $batch->facts[0];
    expect($fact->sourceRef)->toEqual('openrouter:usage:key');
    expect($fact->sourceKind)->toEqual(SourceKind::Usage);
    expect($fact->chargeKind)->toEqual(ChargeKind::Usage);
    expect($fact->evidenceState)->toEqual(EvidenceState::Actual);
    expect($fact->amount?->amountMinor)->toEqual(1234);
    expect($fact->amount?->currency)->toEqual('USD');
});

test('it connects an openrouter account through the provider select', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::providers.index')
        ->set('providerKey', 'openrouter')
        ->set('displayName', 'OpenRouter Main')
        ->set('credential.api_key', 'sk-or-v1-1234567890')
        ->call('connect');

    $component->assertHasNoErrors();

    $this->assertDatabaseHas('provider_accounts', [
        'provider_key' => 'openrouter',
        'display_name' => 'OpenRouter Main',
    ]);
});
