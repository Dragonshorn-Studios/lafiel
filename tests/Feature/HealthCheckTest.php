<?php

use Illuminate\Support\Facades\DB;

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
