<?php

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\TestOvhConnection;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeOvhApi;

it('reports a working payload as connected', function () {
    $api = new FakeOvhApi(['/me' => ovhFixture('me.json')]);

    $check = app(TestOvhConnection::class)->check($api);

    expect($check->status->value)->toBe('connected')
        ->and($check->warning)->toBeNull()
        ->and($api->calls)->toBe(['/me']);
});

it('reports rejected credentials as a distinct state and never retries them', function () {
    Sleep::fake();

    $api = (new FakeOvhApi)->throwOn('/me', [
        new InvalidCredentialsException('OVH rejected the credentials for [/me] (HTTP 401).'),
    ]);

    $check = app(TestOvhConnection::class)->check($api);

    expect($check->status->value)->toBe('rejected')
        ->and($check->warning)->toContain('401')
        ->and($api->callCount('/me'))->toBe(1);
});

it('reports a transient failure as unreachable on a single attempt', function () {
    Sleep::fake();

    $api = (new FakeOvhApi)->throwOn('/me', [
        new TransientProviderException('OVH API server error for [/me] (HTTP 503).'),
    ]);

    $check = app(TestOvhConnection::class)->check($api);

    // The interactive probe never retries: the button is there to
    // press again.
    expect($check->status->value)->toBe('unreachable')
        ->and($check->warning)->toContain('503')
        ->and($api->callCount('/me'))->toBe(1);
});

it('treats a malformed stored payload as rejected, not transient', function () {
    Sleep::fake();

    expect(fn () => app(BuildOvhApi::class)->build(['endpoint' => 'ovh-eu']))
        ->toThrow(InvalidCredentialsException::class);
});
