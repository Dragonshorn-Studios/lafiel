<?php

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\SdkOvhApi;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Ovh\Api;

/**
 * The SDK signs every authenticated request, and its first signed call
 * probes `/auth/time` for the server clock. The mock queue therefore
 * always starts with that response.
 */
function ovhSdkApi(Response|Throwable ...$queued): SdkOvhApi
{
    $handler = new MockHandler([
        new Response(200, [], '1700000000'),
        ...$queued,
    ]);

    $api = new Api(
        ovhPayload()['application_key'],
        ovhPayload()['application_secret'],
        'ovh-eu',
        ovhPayload()['consumer_key'],
        new Client(['handler' => HandlerStack::create($handler)]),
    );

    return new SdkOvhApi($api);
}

it('decodes a successful response body', function () {
    expect(ovhSdkApi(new Response(200, [], json_encode(['nichandle' => 'test-nichandle'], JSON_THROW_ON_ERROR)))->get('/me'))
        ->toBe(['nichandle' => 'test-nichandle']);
});

it('translates 401 and 403 into a rejected-credentials exception', function (int $status) {
    ovhSdkApi(new Response($status))->get('/me');
})
    ->throws(InvalidCredentialsException::class)
    ->with([401, 403]);

it('translates rate limits and server errors into a transient exception', function (int $status) {
    ovhSdkApi(new Response($status))->get('/service');
})
    ->throws(TransientProviderException::class)
    ->with([429, 500, 503]);

it('translates connection failures into a transient exception', function () {
    ovhSdkApi(new ConnectException('cURL error 28: Connection timed out', new Request('GET', 'https://eu.api.ovh.com/1.0/me')))->get('/me');
})->throws(TransientProviderException::class);

it('never carries credential material in an exception message', function () {
    try {
        ovhSdkApi(new Response(401))->get('/me');
        $this->fail('Expected InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())
            ->toContain('/me')
            ->toContain('401')
            ->not->toContain(ovhPayload()['application_secret'])
            ->not->toContain(ovhPayload()['application_key'])
            ->not->toContain(ovhPayload()['consumer_key']);
    }
});

it('implements the read-only interface that cannot express a write', function () {
    expect((new ReflectionClass(SdkOvhApi::class))->implementsInterface(OvhApi::class))->toBeTrue()
        ->and(method_exists(SdkOvhApi::class, 'post'))->toBeFalse()
        ->and(method_exists(SdkOvhApi::class, 'put'))->toBeFalse()
        ->and(method_exists(SdkOvhApi::class, 'delete'))->toBeFalse();
});
