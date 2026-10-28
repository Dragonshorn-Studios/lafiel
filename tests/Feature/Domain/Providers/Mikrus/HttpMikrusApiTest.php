<?php

namespace Tests\Feature\Domain\Providers\Mikrus;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Mikrus\HttpMikrusApi;
use Illuminate\Support\Facades\Http;

it('sends the api key and the default server as form fields on account-level calls', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::sequence([
            [['name' => 'emil100']],
        ]),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    expect($api->post('/serwery'))->toBe([['name' => 'emil100']]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['key'] === mikrusPayload()['api_key']
        && $request['srv'] === mikrusPayload()['default_server']
        && $request->hasHeader('Authorization', mikrusPayload()['api_key']));
});

it('lets an explicitly selected server win over the default', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => ['name' => 'karta200', 'expire' => 1798761600],
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    expect($api->post('/info', ['srv' => 'karta200']))->toBe(['name' => 'karta200', 'expire' => 1798761600]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['key'] === mikrusPayload()['api_key']
        && $request['srv'] === 'karta200'
        && $request['srv'] !== mikrusPayload()['default_server']);
});

it('maps rejected keys to invalid credentials', function (int $status) {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response(null, $status),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    $api->post('/serwery');
})->with([[400], [401], [403]])->throws(InvalidCredentialsException::class);

it('treats a 400 on a per-server call as transient rather than a key rejection', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response('{"error":"unknown srv"}', 400),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    $api->post('/info', ['srv' => 'karta200']);
})->throws(TransientProviderException::class);

it('keeps the plain message when a rejection carries no body', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response(null, 401),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    try {
        $api->post('/serwery');
        $this->fail('Expected an InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toBe('Mikr.us rejected the request for [/serwery] (HTTP 401).');
    }
});

it('surfaces the api error body on a rejected key without leaking the key', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response(
            '{"error":"Invalid API key '.mikrusPayload()['api_key'].'"}',
            400,
        ),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    try {
        $api->post('/serwery');
        $this->fail('Expected an InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toContain('(HTTP 400): {"error":"Invalid API key [redacted]"}')
            ->and($exception->getMessage())->not->toContain(mikrusPayload()['api_key']);
    }
});

it('scrubs, collapses, and strips control bytes from a server failure body', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response("\x1b[31m".mikrusPayload()['api_key']." error:\ttoo\nmany   words\x07", 500),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    try {
        $api->post('/serwery');
        $this->fail('Expected a TransientProviderException.');
    } catch (TransientProviderException $exception) {
        expect($exception->getMessage())
            ->toBe('Mikr.us API request failed for [/serwery] (HTTP 500): [31m[redacted] error: too many words')
            ->not->toContain(mikrusPayload()['api_key'])
            ->not->toContain("\x1b");
    }
});

it('surfaces a capped api error body on server failures', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response(str_repeat('x', 300), 500),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    try {
        $api->post('/serwery');
        $this->fail('Expected a TransientProviderException.');
    } catch (TransientProviderException $exception) {
        expect($exception->getMessage())
            ->toBe('Mikr.us API request failed for [/serwery] (HTTP 500): '.str_repeat('x', 120).'...');
    }
});

it('maps rate limits and server failures to transient', function (int $status) {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response(null, $status),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    $api->post('/serwery');
})->with([[429], [500], [503]])->throws(TransientProviderException::class);

it('maps connection failures to transient and never leaks the key', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::failedConnection('cURL error 28'),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    try {
        $api->post('/serwery');
        $this->fail('Expected a TransientProviderException.');
    } catch (TransientProviderException $exception) {
        expect($exception->getMessage())->toContain('/serwery')
            ->and($exception->getMessage())->not->toContain(mikrusPayload()['api_key']);
    }
});

it('rejects an unreadable body as transient', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response('not-json', 200),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key'], mikrusPayload()['default_server']);

    $api->post('/serwery');
})->throws(TransientProviderException::class);
