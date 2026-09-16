<?php

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Actions\EndManualCost;
use App\Domain\Costs\Actions\UpdateManualCost;
use App\Domain\Costs\Models\CostItem;
use App\Domain\History\Models\CostSnapshot;
use App\Domain\History\TakeSnapshot;
use App\Domain\Inventory\Models\Service;
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

    $capture = app(TakeSnapshot::class)->capture();
    $snapshot = $capture->snapshot;

    expect($capture->written)->toBeFalse()
        ->and($snapshot->snapshot_date->toDateString())->toBe('2026-09-10')
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
    $first = app(TakeSnapshot::class)->capture()->snapshot;

    $this->travel(2)->hours();
    $second = app(TakeSnapshot::class)->capture();

    expect(CostSnapshot::query()->count())->toBe(1)
        ->and($second->written)->toBeFalse()
        ->and($second->snapshot->id)->toBe($first->id)
        // A no-op capture does not even touch the row.
        ->and($second->snapshot->updated_at->toDateTimeString())->toBe($first->updated_at->toDateTimeString());
});

it('updates the same date row when a material input changes', function () {
    $item = snapshotCharge();
    $first = app(TakeSnapshot::class)->capture()->snapshot;

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
    $snapshot = app(TakeSnapshot::class)->capture()->snapshot;

    CostSnapshot::query()->whereKey($snapshot->id)->update(['fx_used' => ['source' => 'manual', 'rate' => 1]]);

    snapshotCharge(['name' => 'Second charge', 'amount' => '10.00']);
    app(TakeSnapshot::class)->capture();

    $capture = app(TakeSnapshot::class)->capture();

    $snapshot->refresh();

    expect($capture?->written)->toBeFalse()
        ->and($capture?->snapshot->id)->toBe($snapshot->id)
        ->and($snapshot->fx_used)->toBe(['source' => 'manual', 'rate' => 1])
        ->and($snapshot->totals['PLN']['monthly_minor'])->toBe(6000);
});

it('replays an explicit past date from cost item history', function () {
    $item = snapshotCharge(['valid_from' => '2026-07-01']);

    $july = app(TakeSnapshot::class)->capture(new CarbonImmutable('2026-07-31 12:00:00'));
    expect($july?->snapshot->totals['PLN']['monthly_minor'])->toBe(5000);

    // The charge was stopped mid-July: replaying July now sees it end.
    app(EndManualCost::class)->end($item, ['valid_to' => '2026-07-15']);

    $replay = app(TakeSnapshot::class)->capture(new CarbonImmutable('2026-07-31 12:00:00'));

    expect($replay?->written)->toBeTrue()
        ->and($replay?->snapshot->id)->toBe($july?->snapshot->id)
        ->and($replay?->snapshot->totals)->toBe([]);
});

it('adds no noise when a re-sync touches observation times only', function () {
    $item = snapshotCharge();
    app(TakeSnapshot::class)->capture();

    // A no-change re-sync bumps observed_at in place; the stored output
    // is identical, so the capture must dedupe.
    $item->observed_at = now()->addDays(3);
    $item->save();

    $second = app(TakeSnapshot::class)->capture();

    expect($second?->snapshot->id)->toBe(CostSnapshot::query()->sole()->id)
        ->and($second->written)->toBeFalse()
        ->and(CostSnapshot::query()->count())->toBe(1);
});

it('records staleness that develops between captures', function () {
    // A synced quote observed today is fresh.
    $account = ProviderAccount::factory()->create();
    $service = Service::factory()->discovered($account)->create();
    $item = CostItem::factory()->subscriptionQuote()->create([
        'amount_minor' => 700,
        'currency' => 'EUR',
        'observed_at' => now(),
    ]);
    $item->services()->attach($service->id);

    app(TakeSnapshot::class)->capture();
    expect(CostSnapshot::query()->sole()->completeness['stale'])->toBe(0);

    // Eight days pass with no new observation: the evidence goes stale,
    // and the same-date capture must record the flip.
    $this->travel(8)->days();
    $second = app(TakeSnapshot::class)->capture();

    expect($second->written)->toBeTrue()
        ->and($second->snapshot->refresh()->completeness['stale'])->toBe(1);
});

it('re-captures when the calculation version changes', function () {
    snapshotCharge();
    app(TakeSnapshot::class)->capture();
    expect(CostSnapshot::query()->sole()->calculation_version)->toBe('v1');

    config(['costs.calculation_version' => 'v2']);

    $second = app(TakeSnapshot::class)->capture();

    expect($second->written)->toBeTrue()
        ->and($second->snapshot->refresh()->calculation_version)->toBe('v2')
        ->and(CostSnapshot::query()->count())->toBe(1);
});

it('is written when a manual cost is updated', function () {
    $item = snapshotCharge();
    app(TakeSnapshot::class)->capture();

    app(UpdateManualCost::class)->update($item, [
        'vendor' => null,
        'name' => 'Snapshot service',
        'category' => 'saas',
        'unknown_amount' => false,
        'amount' => '75.00',
        'currency' => 'PLN',
        'period' => 'monthly',
        'valid_from' => '2026-08-01',
        'valid_to' => null,
        'renews_at' => null,
        'auto_renew' => false,
        'url' => null,
        'notes' => null,
        'price_changed' => true,
    ]);

    expect(CostSnapshot::query()->sole()->totals['PLN']['monthly_minor'])->toBe(7500);
});

it('reports written versus deduped through the command', function () {
    $this->artisan('lafiel:snapshot')
        ->expectsOutputToContain('written')
        ->assertSuccessful();

    $this->artisan('lafiel:snapshot')
        ->expectsOutputToContain('unchanged')
        ->assertSuccessful();
});

it('rejects invalid and future dates', function () {
    $this->artisan('lafiel:snapshot', ['--date' => 'not-a-date'])
        ->expectsOutputToContain('The date must be a Y-m-d value.')
        ->assertFailed();

    $this->artisan('lafiel:snapshot', ['--date' => '2027-01-01'])
        ->expectsOutputToContain('The date must not be in the future.')
        ->assertFailed();

    expect(CostSnapshot::query()->count())->toBe(0);
});

it('asserts every completeness counter in the stored payload', function () {
    // One quote observed long ago (stale) plus a fresh one-time charge
    // without a price (unknown).
    $account = ProviderAccount::factory()->create();
    $service = Service::factory()->discovered($account)->create();
    $stale = CostItem::factory()->subscriptionQuote()->create([
        'amount_minor' => 700,
        'currency' => 'EUR',
        'observed_at' => now()->subDays(30),
    ]);
    $stale->services()->attach($service->id);

    CostItem::factory()->unknownAmount()->create([
        'charge_kind' => 'one_time',
        'period' => 'one_time',
        'observed_at' => now(),
    ]);

    $snapshot = app(TakeSnapshot::class)->capture()?->snapshot;

    // The projector counts the unknown-amount one-time charge as
    // unknown only — the one_time counter applies to priced charges.
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->completeness)->toBe([
            'priced' => 1,
            'unknown' => 1,
            'estimate' => 0,
            'stale' => 1,
            'shared_unallocated' => 0,
            'one_time' => 0,
        ]);
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
    $services = ovhFixture('services-run-a.json');
    $responses = [
        '/me' => ovhFixture('me.json'),
        '/services' => $services,
        '/order/catalog/formatted/vps' => ovhFixture('catalog/vps-eu.json'),
        '/order/catalog/formatted/domain' => ovhFixture('catalog/domain-eu.json'),
        '/order/catalog/formatted/ip' => ovhFixture('catalog/ip-eu.json'),
    ];
    foreach ($services as $service) {
        $responses['/service/'.$service['serviceId'].'/renew'] = ovhFixture('service-renew/'.$service['serviceId'].'.json');
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
        ->and(CostSnapshot::query()->sole()->completeness['priced'])->toBe(3);

    // An identical re-run must add no noise.
    app(SyncOrchestrator::class)->run(queueOvhSnapshotRun($account));
    expect(CostSnapshot::query()->count())->toBe(1);
});
