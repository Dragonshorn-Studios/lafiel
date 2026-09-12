<?php

namespace Tests\Feature\Domain\Providers\Hetzner\Cloud;

use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Hetzner\Cloud\BuildHetznerCloudApi;
use App\Domain\Providers\Hetzner\Cloud\HetznerCloudApi;
use App\Domain\Providers\Hetzner\Cloud\HetznerCloudProviderAdapter;
use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeHetznerCloudApi;

beforeEach(function () {
    $this->freezeTime();
    Sleep::fake();
});

function hetznerAdapter(FakeHetznerCloudApi $api): HetznerCloudProviderAdapter
{
    return new HetznerCloudProviderAdapter(new class($api) extends BuildHetznerCloudApi
    {
        public function __construct(private readonly HetznerCloudApi $api) {}

        public function build(array $payload): HetznerCloudApi
        {
            return $this->api;
        }
    });
}

function hetznerContext(): SyncContext
{
    $account = ProviderAccount::factory()->make(['provider_key' => 'hetzner-cloud']);

    return new SyncContext($account, hetznerCloudPayload(), new CarbonImmutable('2026-09-12 10:00:00'));
}

it('validates credentials with the smallest server listing', function () {
    $api = new FakeHetznerCloudApi(['/servers' => hetznerCloudFixture('servers.json')]);

    $check = hetznerAdapter($api)->validateCredentials(hetznerContext());

    expect($check->valid)->toBeTrue()
        ->and($api->calls)->toBe(['/servers']);
});

it('reports rejected credentials as invalid and never retries', function () {
    Sleep::fake();

    $api = (new FakeHetznerCloudApi)->throwOn('/servers', [
        new InvalidCredentialsException('Hetzner Cloud rejected the credentials for [/servers] (HTTP 401).'),
    ]);

    $check = hetznerAdapter($api)->validateCredentials(hetznerContext());

    expect($check->valid)->toBeFalse()
        ->and($check->warning)->toContain('401')
        ->and($api->callCount('/servers'))->toBe(1);
});

it('maps every billable section into canonical inventory with pricing joins', function () {
    $api = new FakeHetznerCloudApi(hetznerRunPayload());

    $batch = hetznerAdapter($api)->fetchInventory(hetznerContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and(count($batch->items))->toBe(6);

    $server = $batch->items[0];

    expect($server->externalId)->toBe('4200001')
        ->and($server->category)->toBe('compute')
        ->and($server->name)->toBe('web-synthetic-01')
        ->and($server->providerType)->toBe('cx22');

    $volume = $batch->items[5];

    expect($volume->externalId)->toBe('4600001')
        ->and($volume->category)->toBe('storage')
        ->and($volume->name)->toBe('data-synthetic-01')
        ->and($volume->providerType)->toBeNull();
});

it('degrades to a partial batch when a secondary section fails', function () {
    $api = (new FakeHetznerCloudApi(hetznerRunPayload()))->throwOn('/volumes', [
        new TransientProviderException('Hetzner Cloud API server error for [/volumes] (HTTP 503).'),
    ]);

    $batch = hetznerAdapter($api)->fetchInventory(hetznerContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and(count($batch->items))->toBe(5)
        ->and($batch->warnings[0])->toContain('listing [/volumes] failed');
});

it('joins type and location to the catalog as exclusive-VAT estimates', function () {
    $api = new FakeHetznerCloudApi(hetznerRunPayload());
    $adapter = hetznerAdapter($api);
    $batch = $adapter->fetchInventory(hetznerContext());

    $facts = $adapter->fetchCostFacts(hetznerContext(), $batch);

    expect($facts->completeness)->toBe(BatchCompleteness::Complete)
        ->and($facts->completenessFor(ProviderCapability::Subscriptions))->toBe(BatchCompleteness::Complete)
        ->and($facts->reportedCapabilities)->toBe([ProviderCapability::Subscriptions])
        ->and(count($facts->facts))->toBe(6);

    $server = collect($facts->facts)->firstWhere('sourceRef', 'hetzner:cloud:resource:4200001');

    expect($server->amount?->amountMinor)->toBe(499)
        ->and($server->amount?->currency)->toBe('EUR')
        ->and($server->period)->toBe(Period::Monthly)
        ->and($server->evidenceState)->toBe(EvidenceState::Estimate)
        ->and($server->taxBasis)->toBe(TaxBasis::Exclusive)
        ->and($server->notes)->toBe('Hetzner Cloud catalog (net of VAT 19.000000)');
});

it('leaves a resource without a matching catalog entry unknown', function () {
    $api = new FakeHetznerCloudApi(hetznerRunPayload());
    $adapter = hetznerAdapter($api);
    $batch = $adapter->fetchInventory(hetznerContext());

    $facts = $adapter->fetchCostFacts(hetznerContext(), $batch);

    // The recurring charge still exists; only its price is unknown.
    $unknown = collect($facts->facts)->firstWhere('sourceRef', 'hetzner:cloud:resource:4200002');

    expect($unknown)->not->toBeNull()
        ->and($unknown?->amount)->toBeNull()
        ->and($facts->warnings)->toContain('1 resource(s) have no catalog price; their charges stay unknown.');
});

it('prices volumes from the exact per-GB fraction times the size', function () {
    $api = new FakeHetznerCloudApi(hetznerRunPayload());
    $adapter = hetznerAdapter($api);
    $batch = $adapter->fetchInventory(hetznerContext());

    $facts = $adapter->fetchCostFacts(hetznerContext(), $batch);

    // 0.476 × 42 = 19.992 — one half-even rounding lands on 19.99.
    $volume = collect($facts->facts)->firstWhere('sourceRef', 'hetzner:cloud:resource:4600001');

    expect($volume->amount?->amountMinor)->toBe(1999);
});

it('keeps everything unpriced when the catalog cannot be read', function () {
    $api = (new FakeHetznerCloudApi(hetznerRunPayload()))->throwOn('/pricing', [
        new TransientProviderException('Hetzner Cloud API server error for [/pricing] (HTTP 503).'),
    ]);
    $adapter = hetznerAdapter($api);
    $batch = $adapter->fetchInventory(hetznerContext());

    $facts = $adapter->fetchCostFacts(hetznerContext(), $batch);

    expect($facts->completeness)->toBe(BatchCompleteness::Partial)
        ->and(count($facts->facts))->toBe(6)
        ->and(collect($facts->facts)->every(fn ($fact): bool => $fact->amount === null))->toBeTrue()
        ->and($facts->warnings)->toContain('pricing join failed: Hetzner Cloud API server error for [/pricing] (HTTP 503).');
});
