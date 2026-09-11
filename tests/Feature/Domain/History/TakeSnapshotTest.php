<?php

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Actions\EndManualCost;
use App\Domain\Costs\Models\CostItem;
use App\Domain\History\Models\CostSnapshot;
use App\Domain\History\TakeSnapshot;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeOvhApi;

beforeEach(function () {
    Sleep::fake();

    CarbonImmutable::setTestNow('2026-09-10 12:00:00');

    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(OvhProviderAdapter::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * One priced manual charge: 50.00 PLN monthly.
 */
function snapshotCharge(array $overrides = []): CostItem
{
    return app(CreateManualCost::class)->create([
        'vendor' => null,
        'name' => 'Snapshot service',
        'category' => 'saas',
        'unknown_amount' => false,
        'amount' => '50.00',
        'currency' => 'PLN',
        'period' => 'monthly',
        'valid_from' => '2026-08-01',
        'valid_to' => null,
        'renews_at' => null,
        'auto_renew' => false,
        'url' => null,
        'notes' => null,
        'covers_service_id' => null,
        ...$overrides,
    ]);
}

function queueOvhSnapshotRun(ProviderAccount $account): SyncRun
{
    return SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'trigger' => 'manual',
        'status' => SyncStatus::Queued,
        'started_at' => null,
        'finished_at' => null,
    ]);
}

it('captures totals, completeness, and breakdown for the date', function () {
    snapshotCharge();

    $snapshot = app(TakeSnapshot::class)->capture();

    expect($snapshot->snapshot_date->toDateString())->toBe('2026-09-10')
        ->and($snapshot->totals['PLN']['monthly_minor'])->toBe(5000)
        ->and($snapshot->totals['PLN']['annual_minor'])->toBe(60000)
        ->and($snapshot->completeness['priced'])->toBe(1)
        ->and($snapshot->completeness['unknown'])->toBe(0)
        ->and($snapshot->breakdown['lines'])->toHaveCount(1)
        ->and($snapshot->calculation_version)->toBe('v1')
        ->and($snapshot->fx_used)->toBeNull()
        ->and($snapshot->input_checksum)->not->toBeNull();
});

it('adds no noise when inputs are unchanged', function () {
    snapshotCharge();
    $first = app(TakeSnapshot::class)->capture();

    $this->travel(2)->hours();
    $second = app(TakeSnapshot::class)->capture();

    expect(CostSnapshot::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        // A no-op capture does not even touch the row.
        ->and($second->updated_at->toDateTimeString())->toBe($first->updated_at->toDateTimeString());
});

it('updates the same date row when a material input changes', function () {
    $item = snapshotCharge();
    $first = app(TakeSnapshot::class)->capture();

    // End the charge as of yesterday: today's projection no longer
    // carries it at all.
    app(EndManualCost::class)->end($item, ['valid_to' => '2026-09-09']);

    $rows = CostSnapshot::query()->get();

    expect($rows)->toHaveCount(1)
        // No active charges: no currency materializes a total.
        ->and($rows[0]->totals)->toBe([])
        ->and($rows[0]->completeness['priced'])->toBe(0)
        ->and($rows[0]->input_checksum)->not->toBe($first->input_checksum);
});

it('never rewrites fx_used after capture', function () {
    snapshotCharge();
    $snapshot = app(TakeSnapshot::class)->capture();

    CostSnapshot::query()->whereKey($snapshot->id)->update(['fx_used' => ['source' => 'manual', 'rate' => 1]]);

    snapshotCharge(['name' => 'Second charge', 'amount' => '10.00']);
    app(TakeSnapshot::class)->capture();

    $snapshot->refresh();

    expect($snapshot->fx_used)->toBe(['source' => 'manual', 'rate' => 1])
        ->and($snapshot->totals['PLN']['monthly_minor'])->toBe(6000);
});

it('replays an explicit past date from cost item history', function () {
    $item = snapshotCharge(['valid_from' => '2026-07-01']);

    $july = app(TakeSnapshot::class)->capture(new CarbonImmutable('2026-07-31 12:00:00'));
    expect($july->totals['PLN']['monthly_minor'])->toBe(5000);

    // The charge was stopped mid-July: replaying July now sees it end.
    app(EndManualCost::class)->end($item, ['valid_to' => '2026-07-15']);

    $replay = app(TakeSnapshot::class)->capture(new CarbonImmutable('2026-07-31 12:00:00'));

    expect($replay->id)->toBe($july->id)
        ->and($replay->totals)->toBe([]);
});

it('excludes observation times from the material checksum', function () {
    $item = snapshotCharge();
    app(TakeSnapshot::class)->capture();

    // A re-sync touches observed_at in place without changing inputs.
    $item->observed_at = now()->addDays(3);
    $item->save();

    $second = app(TakeSnapshot::class)->capture();

    expect($second->id)->toBe(CostSnapshot::query()->sole()->id)
        ->and(CostSnapshot::query()->count())->toBe(1);
});

it('is written by the lafiel:snapshot command and replayable with a date', function () {
    snapshotCharge();

    $this->artisan('lafiel:snapshot')->assertSuccessful();
    expect(CostSnapshot::query()->count())->toBe(1);

    $this->artisan('lafiel:snapshot')->assertSuccessful();
    expect(CostSnapshot::query()->count())->toBe(1);

    $this->artisan('lafiel:snapshot', ['--date' => '2026-08-01'])->assertSuccessful();
    expect(CostSnapshot::query()->count())->toBe(2)
        ->and(CostSnapshot::query()->whereDate('snapshot_date', '2026-08-01')->sole()->totals['PLN']['monthly_minor'])->toBe(5000);
});

it('is written automatically after a manual cost is created', function () {
    snapshotCharge();

    expect(CostSnapshot::query()->count())->toBe(1)
        ->and(CostSnapshot::query()->sole()->completeness['priced'])->toBe(1);
});

it('is written by a successful provider sync and deduped by the next', function () {
    $names = ovhFixture('service-run-a.json');
    $responses = [
        '/me' => ovhFixture('me.json'),
        '/service' => $names,
        '/order/catalog/formatted/vps' => ovhFixture('catalog/vps-eu.json'),
        '/order/catalog/formatted/cloud' => ovhFixture('catalog/cloud-eu.json'),
        '/order/catalog/formatted/domain' => ovhFixture('catalog/domain-eu.json'),
        '/order/catalog/formatted/ip' => ovhFixture('catalog/ip-eu.json'),
    ];
    foreach ($names as $name) {
        $responses['/service/'.$name] = ovhFixture('service/'.$name.'.json');
    }

    $api = new FakeOvhApi($responses);

    app()->bind(BuildOvhApi::class, fn (): BuildOvhApi => new class($api) extends BuildOvhApi
    {
        public function __construct(private readonly OvhApi $api) {}

        public function build(array $payload): OvhApi
        {
            return $this->api;
        }
    });

    app(AdapterRegistry::class)->register('ovh', app(OvhProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id, 'payload' => ovhPayload()]);

    app(SyncOrchestrator::class)->run(queueOvhSnapshotRun($account));
    expect(CostSnapshot::query()->count())->toBe(1)
        ->and(CostSnapshot::query()->sole()->completeness['priced'])->toBe(2);

    // An identical re-run must add no noise.
    app(SyncOrchestrator::class)->run(queueOvhSnapshotRun($account));
    expect(CostSnapshot::query()->count())->toBe(1);
});
