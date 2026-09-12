<?php

use App\Domain\Providers\Actions\TestProviderConnection;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeOvhApi;

/**
 * The generic connection test goes through the provider adapter, so the
 * OVH client builder is bound to the given fake and the adapter
 * singleton is rebuilt against it — boot already resolved it with the
 * real builder.
 */
function connectionCheckAccount(FakeOvhApi $api): array
{
    app()->bind(BuildOvhApi::class, fn (): BuildOvhApi => new class($api) extends BuildOvhApi
    {
        public function __construct(private readonly OvhApi $api) {}

        public function build(array $payload): OvhApi
        {
            return $this->api;
        }
    });

    app()->forgetInstance(AdapterRegistry::class);
    app()->forgetInstance(OvhProviderAdapter::class);
    app(AdapterRegistry::class)->register('ovh', app(OvhProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    $credential = ProviderCredential::factory()->create([
        'provider_account_id' => $account->id,
        'payload' => ovhPayload(),
    ]);

    return [$account, $credential];
}

it('reports a working payload as connected', function () {
    [$account, $credential] = connectionCheckAccount(new FakeOvhApi(['/me' => ovhFixture('me.json')]));

    $check = app(TestProviderConnection::class)->check($account, $credential);

    expect($check->status->value)->toBe('connected')
        ->and($check->warning)->toBeNull();
});

it('reports rejected credentials as a distinct state and never retries them', function () {
    Sleep::fake();

    $api = (new FakeOvhApi)->throwOn('/me', [
        new InvalidCredentialsException('OVH rejected the credentials for [/me] (HTTP 401).'),
    ]);
    [$account, $credential] = connectionCheckAccount($api);

    $check = app(TestProviderConnection::class)->check($account, $credential);

    expect($check->status->value)->toBe('rejected')
        ->and($check->warning)->toContain('401')
        ->and($api->callCount('/me'))->toBe(1);
});

it('reports a transient failure as unreachable on a single attempt', function () {
    Sleep::fake();

    $api = (new FakeOvhApi)->throwOn('/me', [
        new TransientProviderException('OVH API server error for [/me] (HTTP 503).'),
    ]);
    [$account, $credential] = connectionCheckAccount($api);

    $check = app(TestProviderConnection::class)->check($account, $credential);

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
