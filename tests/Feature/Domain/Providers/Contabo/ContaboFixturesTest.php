<?php

namespace Tests\Feature\Domain\Providers\Contabo;

use Illuminate\Support\Facades\File;

it('ships only parseable JSON fixtures', function () {
    $jsonFiles = array_values(array_filter(
        File::allFiles(contaboFixtureDir()),
        fn (\SplFileInfo $file): bool => $file->getExtension() === 'json',
    ));

    expect($jsonFiles)->not->toBeEmpty();

    foreach ($jsonFiles as $file) {
        contaboFixture(str_replace(contaboFixtureDir().'/', '', $file->getPathname()));
    }

    expect(true)->toBeTrue();
});

it('covers the required cases without secret material', function () {
    $compute = contaboFixture('compute-instances.json');
    $storage = contaboFixture('object-storage-instances.json');

    // Ordinary inventoried resources on both surfaces.
    expect(collect($compute['data'])->pluck('instanceId'))->toContain(100001)
        ->and(collect($storage['data'])->pluck('objectStorageId'))->toContain(200001);

    // No price fields anywhere: Contabo exposes no billing surface, so
    // every price is unknown by design.
    foreach ([$compute, $storage] as $body) {
        foreach ($body['data'] as $instance) {
            expect($instance)->not->toHaveKey('price')
                ->and($instance)->not->toHaveKey('monthlyPrice');
        }
    }
});

it('carries no credential-shaped value anywhere', function () {
    foreach (File::allFiles(contaboFixtureDir()) as $file) {
        $contents = $file->getContents();

        expect($contents)->not->toContain(contaboPayload()['client_id'])
            ->and($contents)->not->toContain(contaboPayload()['client_secret']);
    }
});
