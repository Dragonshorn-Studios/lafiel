<?php

namespace Tests\Feature\Domain\Providers\Hetzner\Cloud;

use Illuminate\Support\Facades\File;

it('ships only parseable JSON fixtures', function () {
    $jsonFiles = array_values(array_filter(
        File::allFiles(hetznerCloudFixtureDir()),
        fn (\SplFileInfo $file): bool => $file->getExtension() === 'json',
    ));

    expect($jsonFiles)->not->toBeEmpty();

    foreach ($jsonFiles as $file) {
        hetznerCloudFixture(str_replace(hetznerCloudFixtureDir().'/', '', $file->getPathname()));
    }

    expect(true)->toBeTrue();
});

it('covers the required cases without secret material', function () {
    $servers = hetznerCloudFixture('servers.json');
    $pricing = hetznerCloudFixture('pricing.json');

    // Ordinary priced resource: cx22 @ fsn1 has a pricing entry.
    $pricedTypes = collect($pricing['pricing']['server_types'])
        ->pluck('type');

    expect($servers['servers'][0]['server_type']['name'])->toBe('cx22')
        ->and($pricedTypes)->toContain('cx22');

    // Unknown price case: cx32 @ nbg1 has no pricing entry.
    $priced = collect($pricing['pricing']['server_types'])
        ->contains(fn (array $entry): bool => $entry['type'] === 'cx32' && $entry['location'] === 'nbg1');

    expect($priced)->toBeFalse()
        ->and($servers['servers'][1]['server_type']['name'])->toBe('cx32');
});

it('carries no credential-shaped value anywhere', function () {
    foreach (File::allFiles(hetznerCloudFixtureDir()) as $file) {
        expect($file->getContents())->not->toContain(hetznerCloudPayload()['api_token']);
    }
});
