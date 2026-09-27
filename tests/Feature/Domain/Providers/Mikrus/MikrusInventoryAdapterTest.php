<?php

namespace Tests\Feature\Domain\Providers\Mikrus;

use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Mikrus\BuildMikrusApi;
use App\Domain\Providers\Mikrus\MikrusApi;
use App\Domain\Providers\Mikrus\MikrusProviderAdapter;
use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeMikrusApi;

function mikrusAdapter(FakeMikrusApi $api): MikrusProviderAdapter
{
    return new MikrusProviderAdapter(new class($api) extends BuildMikrusApi
    {
        public function __construct(private readonly MikrusApi $api) {}

        public function build(array $payload): MikrusApi
        {
            return $this->api;
        }
    });
}

function mikrusContext(): SyncContext
{
    return new SyncContext(
        account: ProviderAccount::factory()->make(['provider_key' => 'mikrus']),
        credentials: mikrusPayload(),
        now: new CarbonImmutable('2026-09-27 12:00:00'),
    );
}

it('declares inventory as its only capability', function () {
    $adapter = mikrusAdapter(new FakeMikrusApi);

    expect($adapter->capabilities()->supports(ProviderCapability::Inventory))->toBeTrue()
        ->and($adapter->capabilities()->costCapabilities())->toBe([])
        ->and($adapter->capabilities()->supports(ProviderCapability::Subscriptions))->toBeFalse();
});

it('inventories servers with expiration metadata from info', function () {
    $adapter = mikrusAdapter(new FakeMikrusApi(mikrusResponses()));

    $batch = $adapter->fetchInventory(mikrusContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and($batch->sourceRef)->toBe('mikrus:/serwery')
        ->and($batch->warnings)->toBe([])
        ->and(count($batch->items))->toBe(2);

    $first = $batch->items[0];

    expect($first->externalId)->toBe('emil100')
        ->and($first->category)->toBe('compute')
        ->and($first->providerType)->toBe('kvm')
        ->and($first->metadata)->toBe([
            'expiration' => 1798761600,
            'is_pro' => 1,
            'cytrus_expiration' => 1767225600,
            'storage_expiration' => 1804406400,
        ]);

    // A listing entry without a virtualization field carries no type.
    expect($batch->items[1]->providerType)->toBeNull()
        ->and($batch->items[1]->metadata['expiration'])->toBe(1798761600);
});

it('degrades to partial and keeps the server when its info fails', function () {
    $api = new FakeMikrusApi(mikrusResponses());
    $api->throwOn('/info', [
        new TransientProviderException('Mikr.us API request failed for [/info] (HTTP 500).'),
        new TransientProviderException('Mikr.us API request failed for [/info] (HTTP 500).'),
    ]);
    $adapter = mikrusAdapter($api);

    $batch = $adapter->fetchInventory(mikrusContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and(count($batch->items))->toBe(2)
        ->and($batch->items[0]->metadata)->toBeNull()
        ->and($batch->warnings)->toContain('info for [emil100] failed: Mikr.us API request failed for [/info] (HTTP 500).')
        ->and($api->callCount('/info'))->toBe(2);
});

it('fails the whole phase when the server listing fails', function () {
    $api = new FakeMikrusApi(mikrusResponses());
    $api->throwOn('/serwery', [
        new TransientProviderException('Mikr.us rate limit reached for [/serwery] (HTTP 429).'),
    ]);
    $adapter = mikrusAdapter($api);

    $adapter->fetchInventory(mikrusContext());
})->throws(TransientProviderException::class);

it('skips listing entries without a name and says so', function () {
    $responses = mikrusResponses();
    $responses['/serwery'] = [
        ['name' => 'emil100'],
        ['ip' => '192.0.2.99'],
        'garbage',
    ];
    $adapter = mikrusAdapter(new FakeMikrusApi($responses));

    $batch = $adapter->fetchInventory(mikrusContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and(count($batch->items))->toBe(1)
        ->and($batch->warnings)->toContain('a server entry without a name was skipped.');
});

it('produces an unsupported empty cost batch and no credential check rejections on a good key', function () {
    $adapter = mikrusAdapter(new FakeMikrusApi(mikrusResponses()));
    $context = mikrusContext();

    $check = $adapter->validateCredentials($context);

    expect($check->valid)->toBeTrue();

    $batch = $adapter->fetchInventory($context);
    $facts = $adapter->fetchCostFacts($context, $batch);

    expect($facts->completeness)->toBe(BatchCompleteness::Unsupported)
        ->and($facts->facts)->toBe([]);
});

it('maps a rejected key during validation to an invalid check', function () {
    $api = new FakeMikrusApi(mikrusResponses());
    $api->throwOn('/serwery', [
        new InvalidCredentialsException('Mikr.us rejected the API key for [/serwery] (HTTP 403).'),
    ]);
    $adapter = mikrusAdapter($api);

    $check = $adapter->validateCredentials(mikrusContext());

    expect($check->valid)->toBeFalse()
        ->and($check->warning)->toContain('HTTP 403');
});

it('propagates a mid-inventory credential rejection instead of degrading to partial', function () {
    $api = new FakeMikrusApi(mikrusResponses());
    $api->throwOn('/info', [
        new InvalidCredentialsException('Mikr.us rejected the API key for [/info] (HTTP 401).'),
    ]);
    $adapter = mikrusAdapter($api);

    $adapter->fetchInventory(mikrusContext());
})->throws(InvalidCredentialsException::class);

it('warns when an info body matches no known lifecycle fields', function () {
    $responses = mikrusResponses();
    $responses['/info'] = fn (array $fields): array => ['name' => $fields['srv'], 'uptime' => 60];
    $adapter = mikrusAdapter(new FakeMikrusApi($responses));

    $batch = $adapter->fetchInventory(mikrusContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and($batch->items[0]->metadata)->toBeNull()
        ->and($batch->warnings)->toContain('info for [emil100] carried no known lifecycle fields; any stored metadata was discarded.');
});

it('classifies a non-list serwery body as transient instead of an empty observation', function () {
    $responses = mikrusResponses();
    $responses['/serwery'] = ['error' => 'bad key', 'message' => 'invalid'];
    $adapter = mikrusAdapter(new FakeMikrusApi($responses));

    $adapter->fetchInventory(mikrusContext());
})->throws(TransientProviderException::class, 'unreadable listing');

it('treats an empty serwery listing as a complete empty account', function () {
    $responses = mikrusResponses();
    $responses['/serwery'] = [];
    $adapter = mikrusAdapter(new FakeMikrusApi($responses));

    $batch = $adapter->fetchInventory(mikrusContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and($batch->items)->toBe([])
        ->and($batch->warnings)->toBe([]);
});
