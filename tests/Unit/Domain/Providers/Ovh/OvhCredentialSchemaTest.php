<?php

use App\Domain\Providers\Ovh\OvhCredentialSchema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('accepts every whitelisted endpoint', function () {
    foreach (OvhCredentialSchema::ENDPOINTS as $endpoint) {
        OvhCredentialSchema::assertValid(ovhPayload(['endpoint' => $endpoint]));

        expect(true)->toBeTrue();
    }
});

it('rejects an unknown endpoint', function () {
    OvhCredentialSchema::assertValid(ovhPayload(['endpoint' => 'example-internal']));
})->throws(ValidationException::class);

it('rejects a payload with missing fields', function () {
    OvhCredentialSchema::assertValid(['endpoint' => 'ovh-eu']);
})->throws(ValidationException::class);

it('normalizes the payload by trimming whitespace', function () {
    $payload = OvhCredentialSchema::payload([
        'endpoint' => ' ovh-eu ',
        'application_key' => '  key-with-space  ',
        'application_secret' => ' secret-value ',
        'consumer_key' => ' consumer-value ',
    ]);

    expect($payload)->toBe([
        'endpoint' => 'ovh-eu',
        'application_key' => 'key-with-space',
        'application_secret' => 'secret-value',
        'consumer_key' => 'consumer-value',
    ]);
});

it('pins the payload schema version', function () {
    expect(OvhCredentialSchema::SCHEMA_VERSION)->toBe(1);
});
