<?php

use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\Ovh\BuildOvhApi;
use App\Domain\Providers\Ovh\OvhApi;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fakes\FakeOvhApi;

beforeEach(function () {
    Sleep::fake();

    $this->actingAs(User::factory()->create());
});

/**
 * Swap the real SDK client builder for one handing out the given fake,
 * so the page can be driven without network access.
 */
function fakeOvhClient(FakeOvhApi $api): void
{
    app()->bind(BuildOvhApi::class, fn (): BuildOvhApi => new class($api) extends BuildOvhApi
    {
        public function __construct(private readonly OvhApi $api) {}

        public function build(array $payload): OvhApi
        {
            return $this->api;
        }
    });
}

function providersPage(): Testable
{
    return Livewire::test('pages::settings.providers');
}

it('lists connected accounts with their verification state', function () {
    ProviderAccount::factory()
        ->has(ProviderCredential::factory()->verified(), 'credentials')
        ->create(['provider_key' => 'ovh', 'display_name' => 'OVH main']);

    ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh', 'display_name' => 'OVH backup']);

    providersPage()
        ->assertSee('OVH main')
        ->assertSee('OVH backup')
        ->assertSee(__('Verified'))
        ->assertSee(__('Not verified'));
});

it('connects an account through the form without rendering the secret', function () {
    providersPage()
        ->set('displayName', 'OVH main')
        ->set('endpoint', 'ovh-eu')
        ->set('applicationKey', ovhPayload()['application_key'])
        ->set('applicationSecret', ovhPayload()['application_secret'])
        ->set('consumerKey', ovhPayload()['consumer_key'])
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSee('OVH main');

    $account = ProviderAccount::query()->where('display_name', 'OVH main')->sole();
    $raw = DB::table('provider_credentials')->where('provider_account_id', $account->id)->sole();

    expect($account->credentials)->toHaveCount(1)
        ->and($raw->payload)->not->toContain(ovhPayload()['application_secret'])
        ->and($raw->payload)->not->toContain(ovhPayload()['consumer_key']);
});

it('marks the connection verified when the test succeeds', function () {
    fakeOvhClient(new FakeOvhApi(['/me' => ovhFixture('me.json')]));

    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('testConnection', $account->id)
        ->assertSee(__('The OVH account accepted the credentials.'))
        ->assertSet('connectionChecks.'.$account->id.'.status', 'connected');

    $credential = $account->credentials()->latest('id')->first();

    expect($credential->refresh()->verified_at)->not->toBeNull()
        ->and($credential->fingerprint)->not->toBeNull();
});

it('shows rejection as a distinct state and leaves verification untouched', function () {
    $api = (new FakeOvhApi)->throwOn('/me', [
        new InvalidCredentialsException('OVH rejected the credentials for [/me] (HTTP 401).'),
    ]);
    fakeOvhClient($api);

    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory()->verified(), 'credentials')
        ->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('testConnection', $account->id)
        ->assertSee('OVH rejected the credentials for [/me] (HTTP 401).')
        ->assertSet('connectionChecks.'.$account->id.'.status', 'rejected');

    $credential = $account->credentials()->latest('id')->first();

    expect($credential->refresh()->verified_at)->not->toBeNull()
        ->and($api->callCount('/me'))->toBe(1);
});

it('reports an unreachable API without marking the credentials rejected', function () {
    $api = (new FakeOvhApi)->throwOn('/me', array_fill(
        0,
        (int) config('sync.retry.max_attempts'),
        new TransientProviderException('OVH API could not be reached for [/me].'),
    ));
    fakeOvhClient($api);

    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('testConnection', $account->id)
        ->assertSee('OVH API could not be reached for [/me].')
        ->assertSet('connectionChecks.'.$account->id.'.status', 'unreachable');

    expect($account->credentials()->latest('id')->first()->refresh()->verified_at)->toBeNull();
});

it('replaces credentials through the edit form', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('edit', $account->id)
        ->set('displayName', 'OVH renamed')
        ->set('endpoint', 'ovh-ca')
        ->set('applicationKey', ovhPayload()['application_key'])
        ->set('applicationSecret', ovhPayload()['application_secret'])
        ->set('consumerKey', ovhPayload()['consumer_key'])
        ->call('update')
        ->assertHasNoErrors();

    $credential = $account->credentials()->latest('id')->first();

    expect($account->refresh()->display_name)->toBe('OVH renamed')
        ->and($account->credentials()->count())->toBe(2)
        ->and($credential->payload['endpoint'])->toBe('ovh-ca')
        ->and($credential->verified_at)->toBeNull();
});

it('toggles sync and disconnects with cascade', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh']);

    providersPage()->call('toggleEnabled', $account->id);
    expect($account->refresh()->enabled)->toBeFalse();

    providersPage()->call('deleteAccount', $account->id);

    expect(ProviderAccount::query()->find($account->id))->toBeNull()
        ->and(DB::table('provider_credentials')->where('provider_account_id', $account->id)->count())->toBe(0);
});

it('never renders stored credential material back into the page', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh']);

    $credential = $account->credentials()->latest('id')->first();
    $secret = $credential->payload['secret'];

    $html = providersPage()->call('edit', $account->id)->html();

    expect($html)->not->toContain($secret);
});

it('queues a sync now through the shared entry point', function () {
    $this->freezeTime();
    $this->app->forgetInstance(AdapterRegistry::class);
    $this->app->forgetInstance(OvhProviderAdapter::class);

    // Credentials that fail validation keep the run offline while still
    // exercising the full shared pipeline.
    $api = (new FakeOvhApi)->throwOn('/me', [
        new InvalidCredentialsException('OVH rejected the credentials for [/me] (HTTP 401).'),
    ]);
    fakeOvhClient($api);
    app(AdapterRegistry::class)->register('ovh', app(OvhProviderAdapter::class));

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('syncNow', $account->id)
        ->assertSee(__('Last run: :status', ['status' => 'failed']), false);

    expect(SyncRun::query()->where('provider_account_id', $account->id)->count())->toBe(1)
        ->and(SyncRun::query()->where('provider_account_id', $account->id)->sole()->trigger)->toBe('manual');
});

it('refuses a second sync while a run is still active', function () {
    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);
    SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => SyncStatus::Running,
        'started_at' => now(),
    ]);

    providersPage()->call('syncNow', $account->id);

    expect(SyncRun::query()->where('provider_account_id', $account->id)->count())->toBe(1);
});
