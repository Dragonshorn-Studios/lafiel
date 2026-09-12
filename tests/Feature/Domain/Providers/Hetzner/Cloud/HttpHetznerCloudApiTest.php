<?php

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Hetzner\Cloud\HttpHetznerCloudApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends the bearer token and returns the decoded body', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response(hetznerCloudFixture('servers.json')),
    ]);

    $body = (new HttpHetznerCloudApi(hetznerCloudPayload()['api_token']))->get('/servers', ['page' => 1, 'per_page' => 50]);

    expect($body)->toHaveKey('servers')
        ->and(Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.hetzner.cloud/v1/servers?page=1&per_page=50'
            && $request->hasHeader('Authorization', 'Bearer '.hetznerCloudPayload()['api_token'])
        ));
});

it('maps rejections to invalid credentials without leaking the token', function (int $status) {
    Http::preventStrayRequests();

    Http::fake([
        'api.hetzner.cloud/v1/*' => Http::response(['error' => ['message' => 'unauthorized']], $status),
    ]);

    try {
        (new HttpHetznerCloudApi(hetznerCloudPayload()['api_token']))->get('/servers');
        $this->fail('Expected InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toContain((string) $status)
            ->and($exception->getMessage())->not->toContain(hetznerCloudPayload()['api_token']);
    }
})->with([[401], [403]]);

it('maps rate limits and server errors to transient failures', function (int $status) {
    Http::preventStrayRequests();

    Http::fake([
        'api.hetzner.cloud/v1/*' => Http::response(['error' => ['message' => 'x']], $status),
    ]);

    expect(fn () => (new HttpHetznerCloudApi(hetznerCloudPayload()['api_token']))->get('/servers'))
        ->toThrow(TransientProviderException::class);
})->with([[429], [500], [503]]);

it('maps connection failures to transient failures', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.hetzner.cloud/v1/*' => Http::failedConnection(),
    ]);

    expect(fn () => (new HttpHetznerCloudApi(hetznerCloudPayload()['api_token']))->get('/servers'))
        ->toThrow(TransientProviderException::class);
});

it('maps an unreadable body to a transient failure', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.hetzner.cloud/v1/*' => Http::response('gateway garbage'),
    ]);

    expect(fn () => (new HttpHetznerCloudApi(hetznerCloudPayload()['api_token']))->get('/servers'))
        ->toThrow(TransientProviderException::class);
});
