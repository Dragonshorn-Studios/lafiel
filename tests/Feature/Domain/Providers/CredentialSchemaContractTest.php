<?php

use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Exceptions\UnsupportedProviderException;

it('keeps the schema triad consistent for every registered provider', function () {
    $schemas = app(CredentialSchemas::class);

    expect($schemas->options())->not->toBeEmpty();

    foreach ($schemas->options() as $providerKey => $label) {
        $schema = $schemas->for($providerKey);
        $fields = collect($schema::fields());

        // Every rendered field must have a validation rule, otherwise
        // input is silently dropped before storage.
        foreach ($fields->pluck('name') as $name) {
            expect($schema::rules())->toHaveKey($name)
                ->and($name)->not->toBe('');
        }

        // Select fields must carry their choices.
        foreach ($fields as $field) {
            if ($field->isSelect()) {
                expect($field->options)->not->toBeEmpty();
            }
        }

        expect($label)->not->toBe('')
            ->and($schema::help())->not->toBe('')
            ->and($schema::summary($schema::payload(array_fill_keys(
                $fields->pluck('name')->all(),
                'x',
            ))))->not->toBe('');
    }
});

it('keeps the adapter registry in lockstep with the schema registry', function () {
    // CredentialSchemas.php says the two registries are populated in
    // lockstep and nothing enforces it — this does: a schema without
    // an adapter means a connectable provider whose first sync fails.
    $schemas = app(CredentialSchemas::class);
    $adapters = app(AdapterRegistry::class);

    foreach (array_keys($schemas->options()) as $providerKey) {
        expect(fn () => $adapters->for($providerKey))->not->toThrow(UnsupportedProviderException::class);
    }
});
