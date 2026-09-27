<?php

namespace Tests\Feature\Domain\Providers\Mikrus;

use Illuminate\Support\Facades\File;

it('ships only parseable JSON fixtures', function () {
    $jsonFiles = array_values(array_filter(
        File::allFiles(mikrusFixtureDir()),
        fn (\SplFileInfo $file): bool => $file->getExtension() === 'json',
    ));

    expect($jsonFiles)->not->toBeEmpty();

    foreach ($jsonFiles as $file) {
        mikrusFixture(str_replace(mikrusFixtureDir().'/', '', $file->getPathname()));
    }

    expect(true)->toBeTrue();
});

it('covers the required cases without price fields', function () {
    $serwery = mikrusFixture('serwery.json');
    $info = mikrusFixture('info.json');

    // Ordinary inventoried servers.
    expect(array_column($serwery, 'name'))->toContain('emil100')
        ->and($info['name'])->toBe('emil100')
        ->and($info)->toHaveKey('expire');

    // No price fields anywhere: mikr.us exposes no billing surface, so
    // no price is ever read — not even to store it as an assumption.
    foreach ([...$serwery, $info] as $entry) {
        expect($entry)->not->toHaveKey('price')
            ->and($entry)->not->toHaveKey('monthlyPrice')
            ->and($entry)->not->toHaveKey('amount');
    }
});

it('carries no credential-shaped value anywhere', function () {
    foreach (File::allFiles(mikrusFixtureDir()) as $file) {
        $contents = $file->getContents();

        expect($contents)->not->toContain(mikrusPayload()['api_key']);
    }
});
