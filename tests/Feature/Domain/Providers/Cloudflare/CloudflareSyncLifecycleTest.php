<?php

namespace Tests\Feature\Domain\Providers\Cloudflare;

use App\Domain\Costs\Enums\AmountState;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Models\CostItem;
use App\Domain\History\Models\CostSnapshot;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Cloudflare\BuildCloudflareApi;
use App\Domain\Providers\Cloudflare\CloudflareApi;
use App\Domain\Providers\Cloudflare\CloudflareProviderAdapter;
use App\Domain\Providers\Enums\ProviderCapability;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\UsageGaps;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\SyncOrchestrator;
use App\Models\User;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeCloudflareApi;

beforeEach(function () {
    $this->freezeTime();
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(CloudflareProviderAdapter::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function emptySubscriptionEnvelope(): array
{
    return [
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => [],
        'result_info' => ['page' => 1, 'per_page' => 50, 'count' => 0, 'total_pages' => 1, 'total_count' => 0],
    ];
}

/**
 * The fixture payload for one complete run. `$proCancelled` scripts the
 * run in which the paid zone dropped its subscription: still a complete
 * subscription observation, and the charge must end.
 */
function cloudflareRunPayload(bool $proCancelled = false): array
{
    return [
        '/user/tokens/verify' => cloudflareFixture('token-verify.json'),
        '/accounts' => cloudflareFixture('accounts.json'),
        '/zones' => cloudflareFixture('zones.json'),
        '/accounts/account-synthetic-01/subscriptions' => cloudflareFixture('account-subscriptions.json'),
        '/zones/zone-synthetic-01/subscriptions' => $proCancelled
            ? emptySubscriptionEnvelope()
            : cloudflareFixture('zone-subscriptions-a.json'),
        '/zones/zone-free-synthetic-02/subscriptions' => cloudflareFixture('zone-subscriptions-b.json'),
    ];
}

/**
 * Register the real Cloudflare adapter against a mutable fake client
 * and create an enabled account with credentials to match.
 */
function cloudflareStack(bool $proCancelled = false): FakeCloudflareApi
{
    $api = new FakeCloudflareApi(cloudflareRunPayload($proCancelled));

    app()->bind(BuildCloudflareApi::class, fn (): BuildCloudflareApi => new class($api) extends BuildCloudflareApi
    {
        public function __construct(private readonly CloudflareApi $api) {}

        public function build(array $payload): CloudflareApi
        {
            return $this->api;
        }
    });

    app(AdapterRegistry::class)->register('cloudflare', app(CloudflareProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'cloudflare', 'display_name' => 'CF synthetic']);
    ProviderCredential::factory()->create(['provider_account_id' => $account->id, 'payload' => cloudflarePayload()]);

    return $api;
}

function cloudflareLifecycleAccount(): ProviderAccount
{
    return ProviderAccount::query()->where('provider_key', 'cloudflare')->sole();
}

function cloudflareSync(ProviderAccount $account): SyncRun
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

function openCharge(ProviderAccount $account, string $subscriptionId): CostItem
{
    return CostItem::query()
        ->where('logical_charge_key', sprintf('cloudflare:account:%d:charge:cf:subscription:%s', $account->id, $subscriptionId))
        ->sole();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('creates the account, zones, and one fact per subscription on the first run', function () {
    cloudflareStack();
    $account = cloudflareLifecycleAccount();

    $run = cloudflareSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and($run->counts['inventory'])->toBe(['seen' => 3, 'created' => 3, 'updated' => 0])
        ->and($run->counts['cost_facts'])->toBe(['seen' => 4, 'created' => 4, 'superseded' => 0, 'updated' => 0, 'renewals' => 4, 'ended' => 0])
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(3);

    $accountService = Service::query()->where('external_id', 'account-synthetic-01')->sole();

    expect($accountService->category)->toBe('account')
        ->and(Service::query()->where('external_id', 'zone-synthetic-01')->sole()->category)->toBe('dns');

    $workers = openCharge($account, 'sub-workers-synthetic-01');

    expect($workers->amount_state)->toBe(AmountState::Known)
        ->and($workers->amount_minor)->toBe(500)
        ->and($workers->currency)->toBe('USD')
        ->and($workers->evidence_state)->toBe(EvidenceState::Actual)
        ->and($workers->notes)->toBe('Rate plan: Workers Paid')
        ->and($workers->renewal?->renews_at?->toDateString())->toBe('2026-10-01')
        ->and($workers->renewal?->auto_renew)->toBeTrue();

    // The free plan is a known zero; the load-balancing subscription
    // without a price component is honestly unknown.
    $free = openCharge($account, 'sub-free-synthetic-02');
    $unknown = openCharge($account, 'sub-unknown-price-synthetic-02');

    expect($free->amount_state)->toBe(AmountState::Known)
        ->and($free->amount_minor)->toBe(0)
        ->and($unknown->amount_state)->toBe(AmountState::Unknown)
        ->and($unknown->amount_minor)->toBeNull();
});

it('is idempotent: re-running the same data adds nothing', function () {
    cloudflareStack();
    $account = cloudflareLifecycleAccount();

    cloudflareSync($account);

    $run = cloudflareSync($account);

    expect($run->refresh()->counts['inventory'])->toBe(['seen' => 3, 'created' => 0, 'updated' => 0])
        ->and($run->counts['cost_facts'])->toBe(['seen' => 4, 'created' => 0, 'superseded' => 0, 'updated' => 0, 'renewals' => 4, 'ended' => 0])
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(3)
        ->and(CostItem::query()->count())->toBe(4);
});

it('ends a cancelled subscription even though usage stays partial', function () {
    $api = cloudflareStack();
    $account = cloudflareLifecycleAccount();

    cloudflareSync($account);

    $pro = openCharge($account, 'sub-pro-synthetic-01');
    expect($pro->refresh()->valid_to)->toBeNull();

    // Run B: the paid zone no longer reports its subscription. The
    // subscriptions capability is still complete, so the absence is
    // positive evidence — the permanent usage partial must not block
    // the charge from ending.
    $api->responses = cloudflareRunPayload(proCancelled: true);
    $this->travel(1)->day();

    $run = cloudflareSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Partial)
        ->and($run->counts['cost_facts']['ended'])->toBe(1)
        ->and($pro->refresh()->valid_to)->not->toBeNull()
        ->and(openCharge($account, 'sub-free-synthetic-02')->refresh()->valid_to)->toBeNull();
});

it('records the usage capability as supported but unhealthy', function () {
    cloudflareStack();
    $account = cloudflareLifecycleAccount();

    cloudflareSync($account);

    $usage = ProviderCapabilityState::query()
        ->where('provider_account_id', $account->id)
        ->where('capability_key', ProviderCapability::Usage->value)
        ->sole();

    $subscriptions = ProviderCapabilityState::query()
        ->where('provider_account_id', $account->id)
        ->where('capability_key', ProviderCapability::Subscriptions->value)
        ->sole();

    expect($usage->supported)->toBeTrue()
        ->and($usage->healthy)->toBeFalse()
        ->and($subscriptions->supported)->toBeTrue()
        ->and($subscriptions->healthy)->toBeTrue();
});

it('surfaces the usage gap on the overview data-quality card', function () {
    cloudflareStack();
    $account = cloudflareLifecycleAccount();

    cloudflareSync($account);

    $response = $this->get(route('overview'));

    $response->assertOk()
        ->assertSee(__(':account: metered usage is unavailable — only fixed subscriptions are counted.', ['account' => 'CF synthetic']), false);

    expect(app(UsageGaps::class)->all())->toBe([['account' => 'CF synthetic']]);
});

it('carries the sanitized warnings into the run summary', function () {
    cloudflareStack();
    $account = cloudflareLifecycleAccount();

    $run = cloudflareSync($account);

    $warnings = $run->refresh()->summary['warnings'] ?? [];

    expect($warnings)->toContain('metered usage is unavailable — fixed subscriptions only; the Cloudflare total is not complete.')
        ->and($warnings)->toContain('1 subscription(s) have no fixed price or currency; their charges stay unknown.');
});

it('fails the run without losing data when credentials are rejected mid-run', function () {
    $api = cloudflareStack();
    $account = cloudflareLifecycleAccount();

    cloudflareSync($account);

    // A verified credential skips validation, so the rejection must
    // surface mid-run, during the inventory fetch.
    $api->throwOn('/accounts', [
        new InvalidCredentialsException('Cloudflare rejected the credentials for [/accounts] (HTTP 401).'),
    ]);
    $this->travel(1)->day();

    $run = cloudflareSync($account);

    expect($run->refresh()->status)->toBe(SyncStatus::Failed)
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(3)
        ->and(CostItem::query()->whereNull('valid_to')->count())->toBe(4);
});

it('captures a snapshot after a run', function () {
    cloudflareStack();
    $account = cloudflareLifecycleAccount();

    cloudflareSync($account);

    expect(CostSnapshot::query()->whereDate('snapshot_date', today())->exists())->toBeTrue();
});
