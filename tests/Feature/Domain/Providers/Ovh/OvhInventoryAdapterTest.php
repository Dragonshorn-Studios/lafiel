<?php

use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use App\Domain\Sync\Validation\ValidateBatches;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeOvhApi;

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
 * The complete run A inventory: the fake serves the /me identity, the
 * run A service listing, and one metadata capture per service.
 */
function ovhRunAFake(): FakeOvhApi
{
    $names = ovhFixture('service-run-a.json');
    $responses = ['/me' => ovhFixture('me.json'), '/service' => $names];

    foreach ($names as $name) {
        $responses['/service/'.$name] = ovhFixture('service/'.$name.'.json');
    }

    return new FakeOvhApi($responses);
}

function ovhContext(ProviderAccount $account): SyncContext
{
    return new SyncContext($account, ovhPayload(), new CarbonImmutable('2026-09-11 10:00:00'));
}

it('maps the fixture inventory onto canonical items by route family', function () {
    $batch = ovhAdapter(ovhRunAFake())->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and($batch->sourceRef)->toBe('ovh:/service')
        ->and($batch->items)->toHaveCount(4);

    $byId = collect($batch->items)->keyBy(fn ($item) => $item->externalId);

    expect($byId['vps-synthetic-01']->category)->toBe('compute')
        ->and($byId['vps-synthetic-01']->providerType)->toBe('vps')
        ->and($byId['domain-zone-synthetic-01']->category)->toBe('dns')
        ->and($byId['domain-zone-synthetic-01']->providerType)->toBe('domain_zone')
        ->and($byId['cloud-project-synthetic-01']->category)->toBe('compute')
        ->and($byId['cloud-project-synthetic-01']->providerType)->toBe('cloud_project')
        ->and($byId['ip-synthetic-01']->category)->toBe('network')
        ->and($byId['ip-synthetic-01']->providerType)->toBe('ip')
        ->and($byId['vps-synthetic-01']->name)->toBe('vps-synthetic-01');
});

it('produces a batch that passes canonical validation', function () {
    $batch = ovhAdapter(ovhRunAFake())->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    app(ValidateBatches::class)->inventory($batch);

    expect(true)->toBeTrue();
});

it('classifies an unmapped route as other with a warning', function () {
    $api = ovhRunAFake();
    $api->responses['/service/vps-synthetic-01']['route'] = '/future/product/future-synthetic-01';

    $batch = ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    $item = collect($batch->items)->firstWhere(fn ($item) => $item->externalId === 'vps-synthetic-01');

    expect($item->category)->toBe('other')
        ->and($item->providerType)->toBe('future')
        ->and($batch->warnings)->toContain('service [vps-synthetic-01] uses unmapped route [/future/product/future-synthetic-01]; classified as other.');
});

it('reports partial completeness when a service metadata call fails transiently', function () {
    $api = ovhRunAFake()->throwOn('/service/ip-synthetic-01', [
        new TransientProviderException('OVH API server error for [/service/ip-synthetic-01] (HTTP 503).'),
    ]);

    $batch = ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create()));

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and($batch->items)->toHaveCount(3)
        ->and($batch->warnings)->toContain('service metadata unavailable for 1 services; inventory is partial.');
});

it('fails the whole phase when the listing itself fails transiently', function () {
    $api = (new FakeOvhApi(['/me' => ovhFixture('me.json')]))->throwOn('/service', [
        new TransientProviderException('OVH rate limit reached for [/service] (HTTP 429).'),
    ]);

    expect(fn () => ovhAdapter($api)->fetchInventory(ovhContext(ProviderAccount::factory()->create())))
        ->toThrow(TransientProviderException::class);
});

it('proves the credentials through GET /me for the orchestrator', function () {
    $adapter = ovhAdapter(ovhRunAFake());
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
    $capabilities = ovhAdapter(ovhRunAFake())->capabilities();

    expect($capabilities->supports(ProviderCapability::Inventory))->toBeTrue()
        ->and($capabilities->supports(ProviderCapability::RenewalQuotes))->toBeTrue()
        ->and($capabilities->supports(ProviderCapability::Usage))->toBeFalse()
        ->and($capabilities->supports(ProviderCapability::Invoices))->toBeFalse();
});
