<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Cloudflare\BuildCloudflareApi;
use App\Domain\Providers\Cloudflare\CloudflareApi;
use App\Domain\Providers\Cloudflare\CloudflareProviderAdapter;
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
 * so the page can be driven without network access. The adapter
 * singleton is rebuilt so it picks up the fake seam — boot already
 * resolved it against the real builder.
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

    app()->forgetInstance(AdapterRegistry::class);
    app()->forgetInstance(OvhProviderAdapter::class);
    app(AdapterRegistry::class)->register('ovh', app(OvhProviderAdapter::class));
}

function providersPage(): Testable
{
    return Livewire::test('pages::providers.index');
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
        ->set('providerKey', 'ovh')
        ->set('displayName', 'OVH main')
        ->set('credential.endpoint', 'ovh-eu')
        ->set('credential.application_key', ovhPayload()['application_key'])
        ->set('credential.application_secret', ovhPayload()['application_secret'])
        ->set('credential.consumer_key', ovhPayload()['consumer_key'])
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
        ->assertSee(__('The provider accepted the credentials.'))
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
        ->set('credential.endpoint', 'ovh-ca')
        ->set('credential.application_key', ovhPayload()['application_key'])
        ->set('credential.application_secret', ovhPayload()['application_secret'])
        ->set('credential.consumer_key', ovhPayload()['consumer_key'])
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

it('clears synced data from the providers page without disconnecting', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh', 'display_name' => 'OVH wipe']);
    $service = Service::factory()->discovered($account)->create();
    CostItem::factory()->create([
        'logical_charge_key' => sprintf('ovh:account:%d:charge:ovh:renew:400010001', $account->id),
        'amount_minor' => 900,
        'currency' => 'EUR',
    ])->services()->attach($service->id);

    providersPage()
        ->assertSee(__('Clear synced data'))
        ->call('clearSyncedData', $account->id)
        ->assertSee('OVH wipe');

    expect(ProviderAccount::query()->find($account->id))->not->toBeNull()
        ->and($account->refresh()->credentials)->toHaveCount(1)
        ->and(Service::query()->where('provider_account_id', $account->id)->count())->toBe(0)
        ->and(CostItem::query()->where('logical_charge_key', 'like', 'ovh:account:'.$account->id.':charge:%')->count())->toBe(0);
});

it('refuses to clear synced data while a sync is running', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh']);
    SyncRun::factory()->create([
        'provider_account_id' => $account->id,
        'status' => SyncStatus::Running,
        'started_at' => now(),
    ]);
    $service = Service::factory()->discovered($account)->create();

    providersPage()->call('clearSyncedData', $account->id);

    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue()
        ->and($account->refresh()->credentials)->toHaveCount(1);
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

    // Credentials that fail validation keep the run offline while still
    // exercising the full shared pipeline.
    $api = (new FakeOvhApi)->throwOn('/me', [
        new InvalidCredentialsException('OVH rejected the credentials for [/me] (HTTP 401).'),
    ]);
    fakeOvhClient($api);

    $account = ProviderAccount::factory()->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('syncNow', $account->id)
        ->assertSee(__('Last run'))
        ->assertSee('failed');

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

it('opens the add-provider slide-over with a clean form', function () {
    providersPage()
        ->call('add')
        ->assertSet('panelOpen', true)
        ->assertSet('displayName', '')
        ->assertSet('providerKey', 'cloudflare')
        ->assertSee(__('Connect :provider', ['provider' => 'Cloudflare']))
        ->set('providerKey', 'ovh')
        ->assertSee(__('Connect :provider', ['provider' => 'OVHcloud']));
});

it('closes the slide-over after connecting an account', function () {
    providersPage()
        ->call('add')
        ->set('providerKey', 'ovh')
        ->set('displayName', 'OVH main')
        ->set('credential.endpoint', 'ovh-eu')
        ->set('credential.application_key', ovhPayload()['application_key'])
        ->set('credential.application_secret', ovhPayload()['application_secret'])
        ->set('credential.consumer_key', ovhPayload()['consumer_key'])
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSet('panelOpen', false);
});

it('opens the slide-over when editing credentials and closes it on save', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'ovh']);

    providersPage()
        ->call('edit', $account->id)
        ->assertSet('panelOpen', true)
        ->set('displayName', 'OVH renamed')
        ->set('credential.endpoint', 'ovh-ca')
        ->set('credential.application_key', ovhPayload()['application_key'])
        ->set('credential.application_secret', ovhPayload()['application_secret'])
        ->set('credential.consumer_key', ovhPayload()['consumer_key'])
        ->call('update')
        ->assertHasNoErrors()
        ->assertSet('panelOpen', false);

    expect($account->refresh()->display_name)->toBe('OVH renamed');
});

it('shows the nothing-here empty state without accounts', function () {
    providersPage()->assertSee(__('Nothing here'));
});

it('connects a cloudflare account through the provider select', function () {
    providersPage()
        ->set('providerKey', 'cloudflare')
        ->set('displayName', 'CF main')
        ->set('credential.api_token', cloudflarePayload()['api_token'])
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSee('CF main');

    $account = ProviderAccount::query()->where('provider_key', 'cloudflare')->sole();

    expect($account->display_name)->toBe('CF main')
        ->and($account->credentials()->latest('id')->first()->payload)->toBe(cloudflarePayload());
});

it('tests a cloudflare connection through the adapter seam', function () {
    $envelope = cloudflareFixture('token-verify.json');

    // Bind the fake seam first, then rebuild the adapter singleton —
    // boot already resolved it against the real builder.
    app()->bind(BuildCloudflareApi::class, fn (): BuildCloudflareApi => new class($envelope) extends BuildCloudflareApi
    {
        public function __construct(private readonly array $envelope) {}

        public function build(array $payload): CloudflareApi
        {
            expect($payload)->toBe(cloudflarePayload());

            return new class($this->envelope) implements CloudflareApi
            {
                public function __construct(private readonly array $envelope) {}

                public function get(string $path, array $query = []): array
                {
                    expect($path)->toBe('/user/tokens/verify');

                    return $this->envelope;
                }
            };
        }
    });

    app()->forgetInstance(AdapterRegistry::class);
    app()->forgetInstance(CloudflareProviderAdapter::class);
    app(AdapterRegistry::class)->register('cloudflare', app(CloudflareProviderAdapter::class));

    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(['payload' => cloudflarePayload()]), 'credentials')
        ->create(['provider_key' => 'cloudflare']);

    providersPage()
        ->call('testConnection', $account->id)
        ->assertSet('connectionChecks.'.$account->id.'.status', 'connected');

    expect($account->credentials()->latest('id')->first()->refresh()->verified_at)->not->toBeNull();
});

it('summarizes a cloudflare account without echoing the token', function () {
    ProviderAccount::factory()
        ->has(ProviderCredential::factory(['payload' => cloudflarePayload()]), 'credentials')
        ->create(['provider_key' => 'cloudflare', 'display_name' => 'CF summary']);

    $html = providersPage()->html();

    expect($html)->toContain('API token')
        ->not->toContain(cloudflarePayload()['api_token']);
});

it('connects a contabo account through the provider select', function () {
    providersPage()
        ->set('providerKey', 'contabo')
        ->set('displayName', 'Contabo main')
        ->set('credential.client_id', contaboPayload()['client_id'])
        ->set('credential.client_secret', contaboPayload()['client_secret'])
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSee('Contabo main');

    $account = ProviderAccount::query()->where('provider_key', 'contabo')->sole();

    expect($account->display_name)->toBe('Contabo main')
        ->and($account->credentials()->latest('id')->first()->payload)->toBe(contaboPayload());
});

it('connects a hetzner cloud account through the provider select', function () {
    providersPage()
        ->set('providerKey', 'hetzner-cloud')
        ->set('displayName', 'HC project')
        ->set('credential.api_token', hetznerCloudPayload()['api_token'])
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSee('HC project');

    $account = ProviderAccount::query()->where('provider_key', 'hetzner-cloud')->sole();

    expect($account->display_name)->toBe('HC project')
        ->and($account->credentials()->latest('id')->first()->payload)->toBe(hetznerCloudPayload());
});

it('swaps the credential fields and the least-privilege notes with the selected provider', function () {
    providersPage()
        ->call('add')
        ->assertSee(__('Connect :provider', ['provider' => 'Cloudflare']))
        // Cloudflare is the label-default: a single token field.
        ->assertSee(__('API token'))
        ->assertDontSee(__('Application key'))
        ->assertSee(__('Read-only :provider credentials', ['provider' => 'Cloudflare']))
        ->set('providerKey', 'ovh')
        // OVH brings its own API credential fields.
        ->assertSee(__('Connect :provider', ['provider' => 'OVHcloud']))
        ->assertSee(__('Endpoint'))
        ->assertSee(__('Application key'))
        ->assertSee(__('Application secret'))
        ->assertSee(__('Consumer key'))
        ->assertDontSee(__('API token'))
        ->assertSee(__('Read-only :provider credentials', ['provider' => 'OVHcloud']))
        ->assertSee(__('How to create :provider credentials', ['provider' => 'OVHcloud']))
        ->set('providerKey', 'contabo')
        ->assertSee(__('Client ID'))
        ->assertSee(__('Client secret'))
        ->assertDontSee(__('Application key'))
        ->assertSee(__('Read-only :provider credentials', ['provider' => 'Contabo']));
});

it('shows the selected provider help at the bottom of the edit slide-over', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory(), 'credentials')
        ->create(['provider_key' => 'contabo']);

    providersPage()
        ->call('edit', $account->id)
        ->assertSee(__('Replace :provider credentials', ['provider' => 'Contabo']))
        ->assertSee(__('Read-only :provider credentials', ['provider' => 'Contabo']));
});

it('binds the provider select live so the panel reacts without a submit', function () {
    // A deferred wire:model only syncs on submit — in the browser the
    // fields and notes would stay stale until then, something the
    // ->set()-driven tests above cannot catch.
    providersPage()
        ->call('add')
        ->assertSee('wire:model.live="providerKey"', false);
});
