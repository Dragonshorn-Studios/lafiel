<?php

use Illuminate\Support\Facades\File;

it('ships only parseable JSON fixtures', function () {
    $jsonFiles = array_values(array_filter(
        File::allFiles(ovhFixtureDir()),
        fn (SplFileInfo $file): bool => $file->getExtension() === 'json',
    ));

    expect($jsonFiles)->not->toBeEmpty();

    foreach ($jsonFiles as $file) {
        ovhFixture(str_replace(ovhFixtureDir().'/', '', $file->getPathname()));
    }

    expect(true)->toBeTrue();
});

it('covers the required cases without secret material', function () {
    $runA = ovhFixture('services-run-a.json');
    $runB = ovhFixture('services-run-b.json');

    expect($runA)->toContain(400010004)
        ->and($runB)->not->toContain(400010004);

    $vps = ovhFixture('services/400010001.json');
    $failover = ovhFixture('services/400010005.json');
    $cloud = ovhFixture('services/400010003.json');
    $ip = ovhFixture('services/400010004.json');

    expect($vps['billing']['pricing']['priceInUcents'])->toBe(700000000)
        ->and($failover['billing']['pricing']['priceInUcents'])->toBe(200000000)
        ->and($cloud['billing']['pricing']['pricingType'])->toBe('consumption')
        ->and($ip['billing']['pricing'])->toBeNull();

    $vpsRenew = ovhFixture('service-renew/400010001.json');

    expect($vpsRenew[0]['strategies'])->toHaveCount(2)
        ->and($vpsRenew[0]['strategies'][1]['services'])->toBe([400010001, 400010005])
        ->and(ovhFixture('service-renew/400010003.json'))->toBe([])
        ->and(ovhFixture('service-renew/400010004.json'))->toBe([]);
});

it('carries no credential-shaped value anywhere', function () {
    foreach (File::allFiles(ovhFixtureDir()) as $file) {
        $contents = $file->getContents();

        expect($contents)->not->toContain('AK0000000000000000')
            ->and($contents)->not->toContain('AS00000000000000000000000000000000')
            ->and($contents)->not->toContain('CK00000000000000000000000000000000')
            ->and($contents)->not->toMatch('/application_secret"\s*:\s*"(?!\[)/');
    }
});
