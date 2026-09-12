<?php

use App\Domain\Providers\Contabo\HttpContaboApi;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('exchanges the client credentials once and reuses the token', function () {
    Http::preventStrayRequests();

    Http::fake([
        'auth.contabo.com/token' => Http::response(['access_token' => 'token-value', 'expires_in' => 900]),
        'api.contabo.com/*' => Http::response(['data' => [], '_metadata' => ['totalCount' => 0]]),
    ]);

    $api = new HttpContaboApi(contaboPayload()['client_id'], contaboPayload()['client_secret']);

    $api->get('/v1/compute/instances');
    $api->get('/v1/compute/instances');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://auth.contabo.com/token')
        && $request['grant_type'] === 'client_credentials'
        && $request['client_id'] === contaboPayload()['client_id']
        && $request['client_secret'] === contaboPayload()['client_secret']
    );
});

it('maps a rejected token exchange to invalid credentials', function (int $status) {
    Http::preventStrayRequests();

    Http::fake([
        'auth.contabo.com/token' => Http::response(['error' => 'invalid_client'], $status),
    ]);

    expect(fn () => (new HttpContaboApi('bad', 'bad'))->get('/v1/compute/instances'))
        ->toThrow(InvalidCredentialsException::class);
})->with([[400], [401], [403]]);

it('maps resource rejections and server errors after a fresh token', function () {
    Http::preventStrayRequests();

    Http::fake([
        'auth.contabo.com/token' => Http::response(['access_token' => 'token-a']),
        'api.contabo.com/*' => Http::sequence()
            ->push(['data' => []], 401)
            ->push(['data' => []], 401),
    ]);

    // The 401 forces one fresh exchange; the second 401 is a rejection.
    try {
        (new HttpContaboApi(contaboPayload()['client_id'], contaboPayload()['client_secret']))->get('/v1/compute/instances');
        $this->fail('Expected InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toContain('401')
            ->and($exception->getMessage())->not->toContain(contaboPayload()['client_secret']);
    }

    Http::assertSentCount(4);
});

it('maps rate limits and server errors to transient failures', function (int $status) {
    Http::preventStrayRequests();

    Http::fake([
        'auth.contabo.com/token' => Http::response(['access_token' => 'token-value']),
        'api.contabo.com/*' => Http::response(['error' => 'x'], $status),
    ]);

    expect(fn () => (new HttpContaboApi(contaboPayload()['client_id'], contaboPayload()['client_secret']))->get('/v1/compute/instances'))
        ->toThrow(TransientProviderException::class);
})->with([[429], [500], [503]]);

it('maps connection failures to transient failures', function () {
    Http::preventStrayRequests();

    Http::fake([
        'auth.contabo.com/token' => Http::response(['access_token' => 'token-value']),
        'api.contabo.com/*' => Http::failedConnection(),
    ]);

    expect(fn () => (new HttpContaboApi(contaboPayload()['client_id'], contaboPayload()['client_secret']))->get('/v1/compute/instances'))
        ->toThrow(TransientProviderException::class);
});

it('refreshes an expired token once and returns the retried body', function () {
    Http::preventStrayRequests();

    Http::fake([
        'auth.contabo.com/token' => Http::sequence()
            ->push(['access_token' => 'token-one'])
            ->push(['access_token' => 'token-two']),
        'api.contabo.com/*' => Http::sequence()
            ->push(['data' => ['stale']], 401)
            ->push(['data' => ['fresh'], '_metadata' => ['totalCount' => 1]]),
    ]);

    $body = (new HttpContaboApi(contaboPayload()['client_id'], contaboPayload()['client_secret']))->get('/v1/compute/instances');

    expect($body['data'])->toBe(['fresh']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'auth.contabo.com')
        ? $request['client_secret'] === contaboPayload()['client_secret']
        : $request->hasHeader('Authorization', 'Bearer token-'.(str_contains($request->header('Authorization')[0] ?? '', 'token-one') ? 'one' : 'two'))
    );
});

it('maps an unreadable body to a transient failure', function () {
    Http::preventStrayRequests();

    Http::fake([
        'auth.contabo.com/token' => Http::response(['access_token' => 'token-value']),
        'api.contabo.com/*' => Http::response('gateway garbage'),
    ]);

    expect(fn () => (new HttpContaboApi(contaboPayload()['client_id'], contaboPayload()['client_secret']))->get('/v1/compute/instances'))
        ->toThrow(TransientProviderException::class);
});
