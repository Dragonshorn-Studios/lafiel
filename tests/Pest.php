<?php

use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeOvhApi;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A synthetic OVH credential payload for tests. The values are fake
 * but secret-shaped, so redaction tests can assert they never leak.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function ovhPayload(array $overrides = []): array
{
    return [
        'endpoint' => 'ovh-eu',
        'application_key' => 'AK0000000000000000',
        'application_secret' => 'AS00000000000000000000000000000000',
        'consumer_key' => 'CK00000000000000000000000000000000',
        ...$overrides,
    ];
}

/**
 * Load one synthetic fixture from tests/Fixtures/Ovh.
 */
function ovhFixture(string $path): mixed
{
    $contents = file_get_contents(ovhFixtureDir().'/'.$path);

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Absolute path of the synthetic OVH fixture directory.
 */
function ovhFixtureDir(): string
{
    return __DIR__.'/Fixtures/Ovh';
}

/**
 * A synthetic Cloudflare credential payload for tests. The value is
 * fake but secret-shaped, so redaction tests can assert it never leaks.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function cloudflarePayload(array $overrides = []): array
{
    return [
        'api_token' => 'CF00000000000000000000000000000000000000',
        ...$overrides,
    ];
}

/**
 * Load one synthetic fixture from tests/Fixtures/Cloudflare.
 */
function cloudflareFixture(string $path): mixed
{
    $contents = file_get_contents(cloudflareFixtureDir().'/'.$path);

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Absolute path of the synthetic Cloudflare fixture directory.
 */
function cloudflareFixtureDir(): string
{
    return __DIR__.'/Fixtures/Cloudflare';
}

/**
 * A synthetic Contabo credential payload for tests. The values are
 * fake but secret-shaped, so redaction tests can assert they never leak.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function contaboPayload(array $overrides = []): array
{
    return [
        'client_id' => 'CB00000000000000000000000000000000',
        'client_secret' => 'CBS000000000000000000000000000000000000000000',
        ...$overrides,
    ];
}

/**
 * Load one synthetic fixture from tests/Fixtures/Contabo.
 */
function contaboFixture(string $path): mixed
{
    $contents = file_get_contents(contaboFixtureDir().'/'.$path);

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Absolute path of the synthetic Contabo fixture directory.
 */
function contaboFixtureDir(): string
{
    return __DIR__.'/Fixtures/Contabo';
}

/**
 * A synthetic Hetzner Cloud credential payload for tests. The value is
 * fake but secret-shaped, so redaction tests can assert it never leaks.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, string>
 */
function hetznerCloudPayload(array $overrides = []): array
{
    return [
        'api_token' => 'HC00000000000000000000000000000000000000000000000000000000000000',
        ...$overrides,
    ];
}

/**
 * Load one synthetic fixture from tests/Fixtures/HetznerCloud.
 */
function hetznerCloudFixture(string $path): mixed
{
    $contents = file_get_contents(hetznerCloudFixtureDir().'/'.$path);

    return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Absolute path of the synthetic Hetzner Cloud fixture directory.
 */
function hetznerCloudFixtureDir(): string
{
    return __DIR__.'/Fixtures/HetznerCloud';
}

/**
 * An adapter wired to the given fake API client.
 */
function ovhAdapter(FakeOvhApi $api): OvhProviderAdapter
{
    return new OvhProviderAdapter(new class($api) extends BuildOvhApi
    {
        public function __construct(private readonly OvhApi $api) {}

        public function build(array $payload): OvhApi
        {
            return $this->api;
        }
    });
}

/**
 * Scripted OVH responses for one complete inventory listing: `/me`,
 * the `/services` id list, each expanded `/services/{id}`, each
 * `/service/{id}/renew` fallback payload, and the family catalogs.
 *
 * @return array<string, mixed>
 */
function ovhFixtureResponses(string $listing = 'services-run-a.json'): array
{
    $ids = ovhFixture($listing);
    $responses = ['/me' => ovhFixture('me.json'), '/services' => $ids];

    foreach ($ids as $id) {
        $responses['/services/'.$id] = ovhFixture('services/'.$id.'.json');
        $responses['/service/'.$id.'/renew'] = ovhFixture('service-renew/'.$id.'.json');
    }

    $responses['/order/catalog/formatted/vps'] = ovhFixture('catalog/vps-eu.json');
    $responses['/order/catalog/formatted/domain'] = ovhFixture('catalog/domain-eu.json');
    $responses['/order/catalog/formatted/ip'] = ovhFixture('catalog/ip-eu.json');

    return $responses;
}

/**
 * The complete run A inventory against the published OVH payload
 * shapes (id listing, expanded services, official /renew lists).
 */
function ovhServicesFake(string $listing = 'services-run-a.json'): FakeOvhApi
{
    return new FakeOvhApi(ovhFixtureResponses($listing));
}
