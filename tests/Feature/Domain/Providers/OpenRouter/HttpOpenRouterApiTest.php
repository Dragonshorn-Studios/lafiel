<?php

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\OpenRouter\HttpOpenRouterApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('http openrouter api returns json payload on success', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/auth/key' => Http::response([
            'data' => ['label' => 'Test Key', 'usage' => 10.50],
        ], 200),
    ]);

    $api = new HttpOpenRouterApi('sk-or-v1-valid');
    $response = $api->get('/auth/key');

    expect($response)->toHaveKey('data');
    expect($response['data']['label'])->toEqual('Test Key');
});

test('http openrouter api throws invalid credentials on 401', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/auth/key' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $api = new HttpOpenRouterApi('sk-or-v1-invalid');
    $api->get('/auth/key');
})->throws(InvalidCredentialsException::class);

test('http openrouter api throws invalid credentials on 403', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/auth/key' => Http::response(['error' => 'Forbidden'], 403),
    ]);

    $api = new HttpOpenRouterApi('sk-or-v1-forbidden');
    $api->get('/auth/key');
})->throws(InvalidCredentialsException::class);

test('http openrouter api throws transient exception on 429 rate limit', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/credits' => Http::response(['error' => 'Rate limited'], 429),
    ]);

    $api = new HttpOpenRouterApi('sk-or-v1-test');
    $api->get('/credits');
})->throws(TransientProviderException::class);

test('http openrouter api throws transient exception on 500 server error', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/credits' => Http::response(['error' => 'Internal error'], 500),
    ]);

    $api = new HttpOpenRouterApi('sk-or-v1-test');
    $api->get('/credits');
})->throws(TransientProviderException::class);

test('http openrouter api throws transient exception on connection failure', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/credits' => fn () => throw new ConnectionException('Timeout'),
    ]);

    $api = new HttpOpenRouterApi('sk-or-v1-test');
    $api->get('/credits');
})->throws(TransientProviderException::class);

test('http openrouter api throws provider exception on 400 client error', function () {
    Http::fake([
        'https://openrouter.ai/api/v1/credits' => Http::response(['error' => 'Bad request'], 400),
    ]);

    $api = new HttpOpenRouterApi('sk-or-v1-test');
    $api->get('/credits');
})->throws(ProviderException::class);
