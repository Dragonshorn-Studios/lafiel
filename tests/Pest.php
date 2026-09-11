<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
