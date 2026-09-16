<?php

use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Validation\ValidateBatches;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeOvhApi;

function ovhContext(ProviderAccount $account): SyncContext
{
    return new SyncContext($account, ovhPayload(), new CarbonImmutable('2026-09-11 10:00:00'));
}

it('maps the fixture inventory onto canonical items by service id', function () {
    $batch = ovhAdapter(ovhServicesFake())->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and($batch->sourceRef)->toBe('ovh:/services')
        ->and($batch->items)->toHaveCount(5);

    $byId = collect($batch->items)->keyBy(fn ($item) => $item->externalId);

    expect($byId['400010001']->category)->toBe('compute')
        ->and($byId['400010001']->providerType)->toBe('vps')
        ->and($byId['400010001']->name)->toBe('vps-synthetic-01')
        ->and($byId['400010002']->category)->toBe('dns')
        ->and($byId['400010002']->providerType)->toBe('domain_zone')
        ->and($byId['400010003']->category)->toBe('compute')
        ->and($byId['400010003']->providerType)->toBe('cloud_project')
        ->and($byId['400010004']->category)->toBe('network')
        ->and($byId['400010004']->providerType)->toBe('ip')
        ->and($byId['400010005']->category)->toBe('network')
        ->and($byId['400010005']->providerType)->toBe('ip');
});

it('preserves the expanded-service lifecycle metadata next to the canonical item', function () {
    $batch = ovhAdapter(ovhServicesFake())->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    $vps = collect($batch->items)->firstWhere(fn ($item) => $item->externalId === '400010001');

    expect($vps->metadata['offer'])->toBe('vps-essentials-2025')
        ->and($vps->metadata['status'])->toBe('active')
        ->and($vps->metadata['creation'])->toBe('2026-01-15T09:24:31+01:00')
        ->and($vps->metadata['nextBillingDate'])->toBe('2026-10-15T09:24:31+01:00')
        ->and($vps->metadata['renewMode'])->toBe('automatic')
        ->and($vps->metadata['renewPeriod'])->toBe('P1M');
});

it('loads each listing id through GET /services/{id}', function () {
    $api = ovhServicesFake();

    ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    expect($api->callCount('/services'))->toBe(1)
        ->and($api->callCount('/services/400010001'))->toBe(1)
        ->and($api->callCount('/services/400010005'))->toBe(1);
});

it('produces a batch that passes canonical validation', function () {
    $batch = ovhAdapter(ovhServicesFake())->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    app(ValidateBatches::class)->inventory($batch);

    expect(true)->toBeTrue();
});

it('classifies published product route paths onto canonical families', function (string $path, string $category, string $providerType) {
    $api = ovhServicesFake();
    $api->responses['/services/400010001']['route']['path'] = $path;

    $batch = ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));
    $item = collect($batch->items)->firstWhere(fn ($item) => $item->externalId === '400010001');

    expect($item->category)->toBe($category)
        ->and($item->providerType)->toBe($providerType);
})->with([
    ['/domain/{serviceName}', 'domain', 'domain_name'],
    ['/domain/zone/{zoneName}', 'dns', 'domain_zone'],
    ['/hosting/web/{serviceName}', 'other', 'web_hosting'],
    ['/ipLoadbalancing/{serviceName}', 'network', 'ip_loadbalancing'],
    ['/ip/{ip}', 'network', 'ip'],
    ['/sslGateway/{serviceName}', 'security', 'ssl_gateway'],
    ['/ssl/{serviceName}', 'security', 'ssl'],
    ['/license/cpanel/{serviceName}', 'saas', 'license'],
    ['/vps/{serviceName}', 'compute', 'vps'],
]);

it('classifies an unmapped route path as other with a warning', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010001']['route']['path'] = '/future/product/{serviceName}';

    $batch = ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    $item = collect($batch->items)->firstWhere(fn ($item) => $item->externalId === '400010001');

    expect($item->category)->toBe('other')
        ->and($item->providerType)->toBe('future')
        ->and($batch->warnings)->toContain('service [400010001] uses unmapped route [/future/product/{serviceName}]; classified as other.');
});

it('classifies a non-string route as other with a warning', function () {
    $api = ovhServicesFake();
    $api->responses['/services/400010001']['route'] = ['/vps', 'vps-synthetic-01'];

    $batch = ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    $item = collect($batch->items)->firstWhere(fn ($item) => $item->externalId === '400010001');

    expect($item->category)->toBe('other')
        ->and($item->providerType)->toBe('unknown')
        ->and($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and($batch->warnings)->toContain('service [400010001] returned a non-string route [["/vps","vps-synthetic-01"]]; classified as other.');
});

it('skips malformed listing entries and reports a partial inventory', function () {
    $api = ovhServicesFake();
    $api->responses['/services'][] = ['serviceName' => 'no-service-id'];
    $api->responses['/services'][] = 'not even an id';

    $batch = ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and($batch->items)->toHaveCount(5)
        ->and($batch->warnings)->toContain('2 listing entries were malformed and are skipped; inventory is partial.');
});

it('skips a service whose expanded fetch fails and reports a partial inventory', function () {
    $api = ovhServicesFake()->throwOn('/services/400010002', [
        new TransientProviderException('OVH API server error for [/services/400010002] (HTTP 503).'),
    ]);

    $batch = ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and($batch->items)->toHaveCount(4)
        ->and(collect($batch->items)->pluck('externalId')->all())->not->toContain('400010002')
        ->and($batch->warnings)->toContain('service [400010002] could not be loaded from [/services/400010002]; it is skipped.');
});

it('fails the whole phase when the listing fails transiently', function () {
    $api = (new FakeOvhApi(['/me' => ovhFixture('me.json')]))->throwOn('/services', [
        new TransientProviderException('OVH rate limit reached for [/services] (HTTP 429).'),
    ]);

    expect(fn () => ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create())))
        ->toThrow(TransientProviderException::class);
});

it('fails the whole phase when the listing body is malformed', function () {
    $api = new FakeOvhApi(['/me' => ovhFixture('me.json'), '/services' => 'not-a-list']);

    expect(fn () => ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create())))
        ->toThrow(TransientProviderException::class);
});

it('proves the credentials through GET /me for the orchestrator', function () {
    $adapter = ovhAdapter(ovhServicesFake());
    $context = ovhContext(ProviderAccount::factory()->create());

    expect($adapter->validateCredentials($context)->valid)->toBeTrue();

    $rejecting = ovhAdapter((new FakeOvhApi)->throwOn('/me', [
        new InvalidCredentialsException('OVH rejected the credentials for [/me] (HTTP 403).'),
    ]));
    $check = $rejecting->validateCredentials($context);

    expect($check->valid)->toBeFalse()
        ->and($check->warning)->toContain('403');
});

it('declares renewal quotes as a supported capability', function () {
    $capabilities = ovhAdapter(ovhServicesFake())->capabilities();

    expect($capabilities->supports(ProviderCapability::Inventory))->toBeTrue()
        ->and($capabilities->supports(ProviderCapability::RenewalQuotes))->toBeTrue()
        ->and($capabilities->supports(ProviderCapability::Usage))->toBeFalse()
        ->and($capabilities->supports(ProviderCapability::Invoices))->toBeFalse();
});
