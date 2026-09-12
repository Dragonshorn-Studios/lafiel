<?php

namespace Tests\Feature\Domain\Providers\Contabo;

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Projection\CostProjector;
use App\Domain\History\Models\CostSnapshot;
use App\Domain\Inventory\Models\Service;
use App\Domain\Inventory\ServiceLedger;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Contabo\BuildContaboApi;
use App\Domain\Providers\Contabo\ContaboApi;
use App\Domain\Providers\Contabo\ContaboProviderAdapter;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use App\Models\User;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeContaboApi;

beforeEach(function () {
    $this->freezeTime();
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(ContaboProviderAdapter::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param  array<int, int>  $instanceIds  compute ids to report; omit one
 *                                        to script its disappearance
 */
function contaboRunPayload(array $instanceIds = [100001, 100002]): array
{
    $compute = array_values(array_filter(
        contaboFixture('compute-instances.json')['data'],
        fn (array $instance): bool => in_array($instance['instanceId'], $instanceIds, true),
    ));

    return [
        '/v1/compute/instances' => [
            'data' => $compute,
            '_metadata' => ['totalCount' => count($compute), 'totalPages' => 1, 'currentPage' => 1],
        ],
        '/v1/object-storage/instances' => contaboFixture('object-storage-instances.json'),
    ];
}

/**
 * Register the real Contabo adapter against a mutable fake client and
 * create an enabled account with credentials to match.
 */
function contaboStack(array $instanceIds = [100001, 100002]): FakeContaboApi
{
    $api = new FakeContaboApi(contaboRunPayload($instanceIds));

    app()->bind(BuildContaboApi::class, fn (): BuildContaboApi => new class($api) extends BuildContaboApi
    {
        public function __construct(private readonly ContaboApi $api) {}

        public function build(array $payload): ContaboApi
        {
            return $this->api;
        }
    });

    app(AdapterRegistry::class)->register('contabo', app(ContaboProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'contabo', 'display_name' => 'Contabo synthetic']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id, 'payload' => contaboPayload()]);

    return $api;
}

function contaboLifecycleAccount(): ProviderAccount
{
    return ProviderAccount::query()->where('provider_key', 'contabo')->sole();
}

function contaboSync(ProviderAccount $account): SyncRun
{
    $run = SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
        'started_at' => null,
        'finished_at' => null,
    ]);

    return app(SyncOrchestrator::class)->run($run);
}

function contaboCharge(ProviderAccount $account, int $instanceId): CostItem
{
    return CostItem::query()
        ->where('logical_charge_key', sprintf('contabo:account:%d:charge:contabo:resource:%d', $account->id, $instanceId))
        ->sole();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('inventories resources with an unknown recurring charge each', function () {
    contaboStack();
    $account = contaboLifecycleAccount();

    $run = contaboSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Succeeded)
        ->and($run->counts['inventory'])->toBe(['seen' => 3, 'created' => 3, 'updated' => 0])
        ->and($run->counts['cost_facts'])->toBe(['seen' => 3, 'created' => 3, 'superseded' => 0, 'updated' => 0, 'renewals' => 0, 'ended' => 0])
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(3);

    foreach ([100001, 100002, 200001] as $instanceId) {
        $charge = contaboCharge($account, $instanceId);
        expect($charge->amount_state->value)->toBe('unknown')
            ->and($charge->period)->toBe(Period::Monthly);
    }

    // The projection counts every unpriced resource — the total is
    // honestly incomplete.
    $projection = app(CostProjector::class)->project(now());

    expect($projection->unknownCount)->toBe(3)
        ->and($projection->pricedCount())->toBe(0);
});

it('is idempotent: re-running the same inventory adds nothing', function () {
    contaboStack();
    $account = contaboLifecycleAccount();

    contaboSync($account);

    $run = contaboSync($account);

    expect($run->refresh()->counts['inventory'])->toBe(['seen' => 3, 'created' => 0, 'updated' => 0])
        ->and($run->counts['cost_facts'])->toBe(['seen' => 3, 'created' => 0, 'superseded' => 0, 'updated' => 0, 'renewals' => 0, 'ended' => 0])
        ->and(CostItem::query()->count())->toBe(3);
});

it('lets a manual overlay answer the unknown and keeps it across re-sync and rename', function () {
    $api = contaboStack();
    $account = contaboLifecycleAccount();

    contaboSync($account);
    $service = Service::query()->where('external_id', '100001')->sole();

    // Attach the manual price to the discovered service: the overlay.
    app(CreateManualCost::class)->create([
        'name' => $service->name,
        'category' => 'compute',
        'amount' => '21.50',
        'currency' => 'EUR',
        'period' => 'monthly',
        'valid_from' => now()->toDateString(),
        'covers_service_id' => $service->id,
    ]);

    // The override answers the provider's unknown: 1 priced, 2 unknown
    // (the other resources) instead of 3 unknown and 0 priced.
    $projection = app(CostProjector::class)->project(now());

    expect($projection->unknownCount)->toBe(2)
        ->and($projection->pricedCount())->toBe(1);

    // Re-sync with the PROVIDER renaming the resource: the overlay
    // survives both, bound to the stable identity.
    $renamed = contaboRunPayload();
    $renamed['/v1/compute/instances']['data'][0]['displayName'] = 'web-renamed-synthetic';
    $api->responses = $renamed;
    $this->travel(1)->day();

    contaboSync($account);

    $overlay = CostItem::query()->where('logical_charge_key', 'like', 'manual:charge:%')->sole();

    expect($overlay->refresh()->is_manual_override)->toBeTrue()
        ->and($overlay->valid_to)->toBeNull()
        ->and($overlay->services->sole()->id)->toBe($service->id)
        ->and($overlay->services->sole()->name)->toBe('web-renamed-synthetic')
        ->and(contaboCharge($account, 100001)->refresh()->valid_to)->toBeNull();

    $projection = app(CostProjector::class)->project(now());

    expect($projection->unknownCount)->toBe(2)
        ->and($projection->pricedCount())->toBe(1);
});

it('shows the overlay price and no unknown badge on the services ledger', function () {
    contaboStack();
    $account = contaboLifecycleAccount();

    contaboSync($account);
    $service = Service::query()->where('external_id', '100001')->sole();

    app(CreateManualCost::class)->create([
        'name' => $service->name,
        'category' => 'compute',
        'amount' => '21.50',
        'currency' => 'EUR',
        'period' => 'monthly',
        'valid_from' => now()->toDateString(),
        'covers_service_id' => $service->id,
    ]);

    $row = collect(app(ServiceLedger::class)->rows(now()))
        ->first(fn ($row) => $row->service->id === $service->id);

    expect($row->unknownCount)->toBe(0)
        ->and($row->monthlyMinor)->toBe(2150)
        ->and($row->monthlyCurrency)->toBe('EUR');
});

it('ends the unknown charge of a resource that disappears on a complete run', function () {
    $api = contaboStack();
    $account = contaboLifecycleAccount();

    contaboSync($account);

    // Run B: instance 100002 is gone from a complete inventory. The
    // subscriptions capability reports complete (empty), so absence is
    // positive evidence and the unknown charge ends.
    $api->responses = contaboRunPayload([100001]);
    $this->travel(1)->day();

    $run = contaboSync($account);

    expect($run->refresh()->counts['cost_facts']['ended'])->toBe(1)
        ->and(contaboCharge($account, 100002)->refresh()->valid_to)->not->toBeNull()
        ->and(contaboCharge($account, 100001)->refresh()->valid_to)->toBeNull();
});

it('carries the no-billing-API warning into the run summary', function () {
    contaboStack();
    $account = contaboLifecycleAccount();

    $run = contaboSync($account);

    expect($run->refresh()->summary['warnings'])->toContain('no Contabo billing API — every price is unknown until a manual price is attached to the service.')
        ->and(CostSnapshot::query()->whereDate('snapshot_date', today())->exists())->toBeTrue();
});
