<?php

use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\OpenRouter\BuildOpenRouterApi;
use App\Domain\Providers\OpenRouter\OpenRouterApi;
use App\Domain\Providers\OpenRouter\OpenRouterProviderAdapter;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(OpenRouterProviderAdapter::class);
});

test('openrouter account syncs inventory and cost facts through sync orchestrator', function () {
    $account = ProviderAccount::factory()->create([
        'provider_key' => 'openrouter',
        'display_name' => 'OpenRouter Prod',
    ]);

    ProviderCredential::factory()->create([
        'provider_account_id' => $account->id,
        'payload' => ['api_key' => 'sk-or-v1-testkey'],
    ]);

    $api = Mockery::mock(OpenRouterApi::class);
    $api->shouldReceive('get')->andReturnUsing(function (string $endpoint) {
        if ($endpoint === '/auth/key') {
            return [
                'data' => [
                    'label' => 'Prod Key',
                    'limit' => 100.0,
                    'usage' => 15.50,
                ],
            ];
        }

        if ($endpoint === '/credits') {
            return [
                'data' => [
                    'total_credits' => 50.00,
                    'total_usage' => 15.50,
                ],
            ];
        }

        return [];
    });

    $builder = Mockery::mock(BuildOpenRouterApi::class);
    $builder->shouldReceive('build')->andReturn($api);

    $adapter = new OpenRouterProviderAdapter($builder);
    $registry = app(AdapterRegistry::class);
    $registry->register('openrouter', $adapter);

    $queuedRun = SyncRun::query()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
    ]);

    $orchestrator = app(SyncOrchestrator::class);
    $run = $orchestrator->run($queuedRun);

    expect($run->status)->toEqual(SyncStatus::Succeeded);

    $service = Service::query()->where('provider_account_id', $account->id)->first();
    expect($service)->not->toBeNull();
    expect($service->category)->toEqual('saas');
    expect($service->name)->toEqual('OpenRouter (Prod Key)');

    $costItem = $service->costItems()->first();
    expect($costItem)->not->toBeNull();
    expect($costItem->source_kind)->toEqual(SourceKind::Usage);
    expect($costItem->period)->toEqual(Period::Unknown);
    expect($costItem->amount_minor)->toEqual(1550);
});

test('openrouter sync is idempotent on subsequent runs', function () {
    $account = ProviderAccount::factory()->create([
        'provider_key' => 'openrouter',
        'display_name' => 'OpenRouter Prod',
    ]);

    ProviderCredential::factory()->create([
        'provider_account_id' => $account->id,
        'payload' => ['api_key' => 'sk-or-v1-testkey'],
    ]);

    $api = Mockery::mock(OpenRouterApi::class);
    $api->shouldReceive('get')->andReturnUsing(function (string $endpoint) {
        if ($endpoint === '/auth/key') {
            return ['data' => ['label' => 'Prod Key', 'usage' => 10.00]];
        }

        if ($endpoint === '/credits') {
            return ['data' => ['total_usage' => 10.00]];
        }

        return [];
    });

    $builder = Mockery::mock(BuildOpenRouterApi::class);
    $builder->shouldReceive('build')->andReturn($api);

    $adapter = new OpenRouterProviderAdapter($builder);
    $registry = app(AdapterRegistry::class);
    $registry->register('openrouter', $adapter);

    $orchestrator = app(SyncOrchestrator::class);

    $queuedRun1 = SyncRun::query()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
    ]);
    $run1 = $orchestrator->run($queuedRun1);
    expect($run1->status)->toEqual(SyncStatus::Succeeded);

    $queuedRun2 = SyncRun::query()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
    ]);
    $run2 = $orchestrator->run($queuedRun2);
    expect($run2->status)->toEqual(SyncStatus::Succeeded);

    expect(Service::query()->where('provider_account_id', $account->id)->count())->toEqual(1);
});
