<?php

use App\Domain\Providers\Cloudflare\HttpCloudflareApi;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function cloudflareApi(): HttpCloudflareApi
{
    return new HttpCloudflareApi(cloudflarePayload()['api_token']);
}

it('returns the decoded envelope on success', function () {
    Http::preventStrayRequests();

    $envelope = cloudflareFixture('accounts.json');

    Http::fake([
        'api.cloudflare.com/client/v4/accounts' => Http::response($envelope),
    ]);

    expect(cloudflareApi()->get('/accounts'))->toBe($envelope);
});

it('sends the bearer token and paginates nothing by itself', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.cloudflare.com/client/v4/zones*' => Http::response(cloudflareFixture('zones.json')),
    ]);

    cloudflareApi()->get('/zones', ['page' => 2, 'per_page' => 50]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/zones?page=2&per_page=50'
        && $request->hasHeader('Authorization', 'Bearer '.cloudflarePayload()['api_token'])
    );
});

it('maps rejections to invalid credentials without leaking secrets', function (int $status) {
    Http::preventStrayRequests();

    Http::fake([
        'api.cloudflare.com/client/v4/*' => Http::response(['success' => false], $status),
    ]);

    try {
        cloudflareApi()->get('/zones');
        $this->fail('Expected InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toBe("Cloudflare rejected the credentials for [/zones] (HTTP {$status}).")
            ->and($exception->getMessage())->not->toContain(cloudflarePayload()['api_token']);
    }
})->with([[401], [403]]);

it('maps rate limits and server errors to transient failures', function (int $status) {
    Http::preventStrayRequests();

    Http::fake([
        'api.cloudflare.com/client/v4/*' => Http::response(['success' => false], $status),
    ]);

    try {
        cloudflareApi()->get('/zones');
        $this->fail('Expected TransientProviderException.');
    } catch (TransientProviderException $exception) {
        expect($exception->getMessage())->toContain((string) $status)
            ->and($exception->getMessage())->not->toContain(cloudflarePayload()['api_token']);
    }
})->with([[429], [500], [503]]);

it('maps connection failures to transient failures', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.cloudflare.com/client/v4/*' => Http::failedConnection(),
    ]);

    expect(fn () => cloudflareApi()->get('/zones'))
        ->toThrow(TransientProviderException::class);
});

it('refuses a 2xx envelope that reports failure without retrying', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.cloudflare.com/client/v4/*' => Http::response(['success' => false, 'errors' => [['code' => 1234, 'message' => '[redacted]']]]),
    ]);

    expect(fn () => cloudflareApi()->get('/zones'))
        ->toThrow(ProviderException::class);
});

it('never exposes a mutating verb on the client', function () {
    $api = cloudflareApi();

    expect(method_exists($api, 'post'))->toBeFalse()
        ->and(method_exists($api, 'put'))->toBeFalse()
        ->and(method_exists($api, 'delete'))->toBeFalse()
        ->and(method_exists($api, 'patch'))->toBeFalse();
});
