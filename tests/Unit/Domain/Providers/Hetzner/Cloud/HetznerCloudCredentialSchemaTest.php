<?php

use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Hetzner\Cloud\HetznerCloudCredentialSchema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('validates the schema rules and normalizes the payload', function () {
    $payload = HetznerCloudCredentialSchema::payload([
        'api_token' => '  token-value  ',
    ]);

    expect($payload)->toBe(['api_token' => 'token-value']);

    HetznerCloudCredentialSchema::assertValid($payload);
    expect(HetznerCloudCredentialSchema::SCHEMA_VERSION)->toBe(1);
});

it('rejects a payload without a token', function () {
    HetznerCloudCredentialSchema::assertValid(['api_token' => '']);
})->throws(ValidationException::class);

it('is registered for the hetzner-cloud provider key', function () {
    app(CredentialSchemas::class)->register('hetzner-cloud-test', HetznerCloudCredentialSchema::class);

    expect(app(CredentialSchemas::class)->for('hetzner-cloud-test'))->toBe(HetznerCloudCredentialSchema::class)
        ->and(HetznerCloudCredentialSchema::label())->toBe('Hetzner Cloud')
        ->and(HetznerCloudCredentialSchema::summary(['api_token' => 'x']))->toBe('API token');
});
