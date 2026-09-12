<?php

use App\Domain\Providers\Actions\ConnectOvhAccount;
use App\Domain\Providers\Actions\DeleteProviderAccount;
use App\Domain\Providers\Actions\SetProviderAccountEnabled;
use App\Domain\Providers\Actions\UpdateOvhCredentials;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function connectOvhAccount(array $overrides = []): ProviderAccount
{
    return app(ConnectOvhAccount::class)->connect([
        'display_name' => 'OVH main',
        ...ovhPayload(),
        ...$overrides,
    ]);
}

it('creates an enabled ovh account with one encrypted credential', function () {
    $account = connectOvhAccount();

    expect($account->provider_key)->toBe('ovh')
        ->and($account->display_name)->toBe('OVH main')
        ->and($account->enabled)->toBeTrue()
        ->and($account->credentials)->toHaveCount(1);

    $credential = $account->credentials->sole();

    expect($credential->schema_version)->toBe(1)
        ->and($credential->verified_at)->toBeNull()
        ->and($credential->fingerprint)->toBeNull()
        ->and($credential->payload)->toBe(ovhPayload());
});

it('stores the credential payload encrypted at rest', function () {
    $account = connectOvhAccount();

    $raw = DB::table('provider_credentials')->where('provider_account_id', $account->id)->first();

    expect($raw->payload)->not->toContain(ovhPayload()['application_secret'])
        ->not->toContain(ovhPayload()['application_key'])
        ->not->toContain(ovhPayload()['consumer_key']);
});

it('rejects an invalid payload or missing display name', function (array $overrides) {
    connectOvhAccount($overrides);
})
    ->throws(ValidationException::class)
    ->with([
        'unknown endpoint' => [['endpoint' => 'example-internal']],
        'empty secret' => [['application_secret' => '']],
        'empty consumer key' => [['consumer_key' => '']],
        'empty display name' => [['display_name' => '']],
    ]);

it('allows several ovh accounts side by side', function () {
    connectOvhAccount(['display_name' => 'OVH main', 'application_key' => 'AK-second-account']);
    connectOvhAccount(['display_name' => 'OVH billing']);

    expect(ProviderAccount::query()->where('provider_key', 'ovh')->count())->toBe(2);
});

it('replaces credentials with a new unverified row and keeps the old one', function () {
    $account = connectOvhAccount();

    $old = $account->credentials()->latest('id')->first();
    $old->verified_at = now();
    $old->fingerprint = 'existing-fingerprint';
    $old->save();

    $new = app(UpdateOvhCredentials::class)->update($account, [
        'display_name' => 'OVH renamed',
        ...ovhPayload(['application_secret' => 'AS-replacement-secret-9999']),
    ]);

    expect($account->refresh()->display_name)->toBe('OVH renamed')
        ->and($account->credentials()->count())->toBe(2)
        ->and($new->id)->not->toBe($old->id)
        ->and($new->verified_at)->toBeNull()
        ->and($new->fingerprint)->toBeNull()
        ->and($new->payload['application_secret'])->toBe('AS-replacement-secret-9999');

    $old->refresh();
    expect($old->verified_at)->not->toBeNull();
});

it('enables and disables an account in place', function () {
    $account = connectOvhAccount();

    app(SetProviderAccountEnabled::class)->set($account, false);
    expect($account->refresh()->enabled)->toBeFalse();

    app(SetProviderAccountEnabled::class)->set($account, true);
    expect($account->refresh()->enabled)->toBeTrue();
});

it('deletes the account and cascades credentials, services, and runs', function () {
    $account = ProviderAccount::factory()
        ->has(ProviderCredential::factory()->count(2), 'credentials')
        ->create(['provider_key' => 'ovh']);

    app(DeleteProviderAccount::class)->delete($account);

    expect(ProviderAccount::query()->find($account->id))->toBeNull()
        ->and(DB::table('provider_credentials')->where('provider_account_id', $account->id)->count())->toBe(0);
});
