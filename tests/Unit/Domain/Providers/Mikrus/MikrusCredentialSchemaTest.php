<?php

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Mikrus\BuildMikrusApi;
use App\Domain\Providers\Mikrus\MikrusCredentialSchema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('labels and summarizes the schema without echoing secrets', function () {
    expect(MikrusCredentialSchema::label())->toBe('Mikr.us')
        ->and(MikrusCredentialSchema::summary(MikrusCredentialSchema::payload(['api_key' => ' MIKKEY123 '])))
        ->toBe('API key')
        ->and(MikrusCredentialSchema::summary([]))->toBe('API key');
});

it('trims the key into the stored payload', function () {
    $payload = MikrusCredentialSchema::payload(['api_key' => '  MIKKEY123  ']);

    expect($payload)->toBe(['api_key' => 'MIKKEY123']);
});

it('validates the payload shape', function () {
    MikrusCredentialSchema::assertValid(['api_key' => 'MIKKEY123']);

    MikrusCredentialSchema::assertValid([]);
})->throws(ValidationException::class);

it('lists exactly one secret field', function () {
    $fields = MikrusCredentialSchema::fields();

    expect(count($fields))->toBe(1)
        ->and($fields[0]->name)->toBe('api_key')
        ->and($fields[0]->isSecret())->toBeTrue();
});

it('maps an at-rest schema mismatch to invalid credentials naming the field', function () {
    try {
        (new BuildMikrusApi)->build(['wrong' => 'x']);
        $this->fail('Expected InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toContain('api_key')
            ->and($exception->getPrevious())->toBeInstanceOf(ValidationException::class);
    }
});
