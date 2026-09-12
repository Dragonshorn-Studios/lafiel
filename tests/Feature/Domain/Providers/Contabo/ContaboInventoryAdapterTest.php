<?php

namespace Tests\Feature\Domain\Providers\Contabo;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Providers\Contabo\BuildContaboApi;
use App\Domain\Providers\Contabo\ContaboApi;
use App\Domain\Providers\Contabo\ContaboProviderAdapter;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Enums\BatchCompleteness;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeContaboApi;

beforeEach(function () {
    $this->freezeTime();
    Sleep::fake();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function contaboAdapter(FakeContaboApi $api): ContaboProviderAdapter
{
    return new ContaboProviderAdapter(new class($api) extends BuildContaboApi
    {
        public function __construct(private readonly ContaboApi $api) {}

        public function build(array $payload): ContaboApi
        {
            return $this->api;
        }
    });
}

function contaboContext(): SyncContext
{
    $account = ProviderAccount::factory()->make(['provider_key' => 'contabo']);

    return new SyncContext($account, contaboPayload(), new CarbonImmutable('2026-09-12 10:00:00'));
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('validates credentials with the smallest compute listing', function () {
    $api = new FakeContaboApi(['/v1/compute/instances' => contaboFixture('compute-instances.json')]);

    $check = contaboAdapter($api)->validateCredentials(contaboContext());

    expect($check->valid)->toBeTrue()
        ->and($api->calls)->toBe(['/v1/compute/instances']);
});

it('reports rejected credentials as invalid and never retries', function () {
    Sleep::fake();

    $api = (new FakeContaboApi)->throwOn('/v1/compute/instances', [
        new InvalidCredentialsException('Contabo rejected the credentials for [/v1/compute/instances] (HTTP 403).'),
    ]);

    $check = contaboAdapter($api)->validateCredentials(contaboContext());

    expect($check->valid)->toBeFalse()
        ->and($check->warning)->toContain('403')
        ->and($api->callCount('/v1/compute/instances'))->toBe(1);
});

it('maps compute instances and object storage into canonical inventory', function () {
    $api = new FakeContaboApi([
        '/v1/compute/instances' => contaboFixture('compute-instances.json'),
        '/v1/object-storage/instances' => contaboFixture('object-storage-instances.json'),
    ]);

    $batch = contaboAdapter($api)->fetchInventory(contaboContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Complete)
        ->and(count($batch->items))->toBe(3);

    $compute = $batch->items[0];

    expect($compute->externalId)->toBe('100001')
        ->and($compute->category)->toBe('compute')
        ->and($compute->name)->toBe('web-synthetic-01')
        ->and($compute->providerType)->toBe('v1');

    $storage = $batch->items[2];

    expect($storage->externalId)->toBe('200001')
        ->and($storage->category)->toBe('storage')
        ->and($storage->name)->toBe('backup-synthetic-01')
        ->and($storage->providerType)->toBe('object_storage');
});

it('degrades to a partial batch when object storage fails but keeps compute', function () {
    $api = (new FakeContaboApi([
        '/v1/compute/instances' => contaboFixture('compute-instances.json'),
    ]))->throwOn('/v1/object-storage/instances', [
        new TransientProviderException('Contabo API server error for [/v1/object-storage/instances] (HTTP 503).'),
    ]);

    $batch = contaboAdapter($api)->fetchInventory(contaboContext());

    expect($batch->completeness)->toBe(BatchCompleteness::Partial)
        ->and(count($batch->items))->toBe(2)
        ->and($batch->warnings[0])->toContain('object storage listing failed');
});

it('emits one unknown recurring fact per discovered resource', function () {
    $api = new FakeContaboApi([
        '/v1/compute/instances' => contaboFixture('compute-instances.json'),
        '/v1/object-storage/instances' => contaboFixture('object-storage-instances.json'),
    ]);
    $adapter = contaboAdapter($api);
    $batch = $adapter->fetchInventory(contaboContext());

    $facts = $adapter->fetchCostFacts(contaboContext(), $batch);

    expect($facts->completeness)->toBe(BatchCompleteness::Complete)
        ->and(count($facts->facts))->toBe(3)
        ->and($facts->completenessFor(ProviderCapability::Subscriptions))->toBe(BatchCompleteness::Complete)
        ->and($facts->reportedCapabilities)->toBe([ProviderCapability::Subscriptions]);

    $first = $facts->facts[0];

    expect($first->sourceRef)->toBe('contabo:resource:100001')
        ->and($first->serviceExternalIds)->toBe(['100001'])
        ->and($first->amount)->toBeNull()
        ->and($first->period)->toBe(Period::Monthly)
        ->and($first->evidenceState)->toBe(EvidenceState::Estimate)
        ->and($first->chargeKind)->toBe(ChargeKind::RecurringFixed);
});
