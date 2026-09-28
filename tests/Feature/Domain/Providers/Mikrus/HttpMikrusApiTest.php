<?php

namespace Tests\Feature\Domain\Providers\Mikrus;

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Mikrus\HttpMikrusApi;
use Illuminate\Support\Facades\Http;

it('sends the api key as a form field on every call', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::sequence([
            [['name' => 'emil100']],
            ['name' => 'emil100', 'expire' => 1798761600],
        ]),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key']);

    expect($api->post('/serwery'))->toBe([['name' => 'emil100']])
        ->and($api->post('/info', ['srv' => 'emil100']))->toBe(['name' => 'emil100', 'expire' => 1798761600]);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['key'] === mikrusPayload()['api_key']
        && isset($request['srv']) && $request['srv'] === 'emil100');
});

it('maps rejected keys to invalid credentials', function (int $status) {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response(null, $status),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key']);

    $api->post('/serwery');
})->with([[401], [403]])->throws(InvalidCredentialsException::class);

it('maps rate limits and server failures to transient', function (int $status) {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::response(null, $status),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key']);

    $api->post('/serwery');
})->with([[429], [500], [503]])->throws(TransientProviderException::class);

it('maps connection failures to transient and never leaks the key', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.mikr.us/*' => Http::failedConnection('cURL error 28'),
    ]);

    $api = new HttpMikrusApi(mikrusPayload()['api_key']);

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

    $api = new HttpMikrusApi(mikrusPayload()['api_key']);

    $api->post('/serwery');
})->throws(TransientProviderException::class);
