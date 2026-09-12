<?php

use App\Domain\Providers\CredentialSchemas;

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
