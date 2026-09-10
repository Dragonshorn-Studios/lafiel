<?php

use App\Domain\Providers\Models\ProviderCredential;
use Illuminate\Support\Facades\DB;

test('credentials are stored encrypted at rest', function () {
    $credential = ProviderCredential::factory()->create([
        'payload' => ['key' => 'ovh-read-only', 'secret' => 'super-secret-value'],
    ]);

    $raw = DB::table('provider_credentials')->find($credential->id);

    expect($raw->payload)->not->toContain('super-secret-value');
    expect($raw->payload)->not->toContain('ovh-read-only');

    // And they decrypt through the model with the same APP_KEY.
    $credential->refresh();

    expect($credential->payload['secret'])->toEqual('super-secret-value');
});

test('credentials never appear in serialization', function () {
    $credential = ProviderCredential::factory()->create();

    foreach ([$credential->toArray(), $credential->toJson(), $credential->jsonSerialize()] as $serialized) {
        $serializedString = is_string($serialized) ? $serialized : json_encode($serialized);

        expect($serializedString)->not->toContain('payload');
    }
});
