<?php

use App\Domain\Providers\AdapterRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeProviderAdapter;

test('health endpoint reports the application and database as up', function () {
    $response = $this->getJson('/up');

    $response->assertOk();
    $response->assertJsonPath('status', 'up');
});

test('health endpoint reports down when the database is unreachable', function () {
    config(['app.debug' => false]);

    DB::shouldReceive('select')->andThrow(new RuntimeException('connection refused'));

    $response = $this->getJson('/up');

    $response->assertStatus(500);
    $response->assertJsonPath('status', 'down');
});

test('health checks never reach a provider adapter', function () {
    $adapter = new FakeProviderAdapter;
    app(AdapterRegistry::class)->register('probe-provider', $adapter);

    $this->getJson('/up')->assertOk();

    expect($adapter->validateCalls)->toBe(0)
        ->and($adapter->inventoryCalls)->toBe(0)
        ->and($adapter->costCalls)->toBe(0);
});
