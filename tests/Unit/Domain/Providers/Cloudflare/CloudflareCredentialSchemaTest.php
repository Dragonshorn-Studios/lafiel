<?php

use App\Domain\Providers\Cloudflare\CloudflareCredentialSchema;
use App\Domain\Providers\CredentialSchemas;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('validates the schema rules and normalizes the payload', function () {
    $rules = CloudflareCredentialSchema::rules();

    expect($rules['api_token'])->toContain('required');

    $payload = CloudflareCredentialSchema::payload([
        'api_token' => '  token-value  ',
    ]);

    expect($payload)->toBe(['api_token' => 'token-value']);

    CloudflareCredentialSchema::assertValid($payload);
    expect(CloudflareCredentialSchema::SCHEMA_VERSION)->toBe(1);
});

it('rejects a payload without a token', function () {
    CloudflareCredentialSchema::assertValid(['api_token' => '']);
})->throws(ValidationException::class);

it('is registered for the cloudflare provider key', function () {
    app(CredentialSchemas::class)->register('cloudflare-test', CloudflareCredentialSchema::class);

    expect(app(CredentialSchemas::class)->for('cloudflare-test'))->toBe(CloudflareCredentialSchema::class)
        ->and(CloudflareCredentialSchema::label())->toBe('Cloudflare')
        ->and(CloudflareCredentialSchema::summary(['api_token' => 'x']))->toBe('API token')
        ->and(collect(CloudflareCredentialSchema::fields())->pluck('name')->all())->toBe(['api_token']);
});
