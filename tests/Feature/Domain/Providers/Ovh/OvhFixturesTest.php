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
    $idsOf = fn (array $run): array => array_column($run, 'serviceId');

    // Missing on the next complete run: present in run A, absent in run B.
    expect($idsOf($runA))->toContain(400010004)
        ->and($idsOf($runB))->not->toContain(400010004);

    // A multi-service renewal strategy and a renew payload without a
    // selected price (Public Cloud gap + catalog fallback) exist.
    expect(count(ovhFixture('service-renew/400010001.json')['services']))->toBe(2)
        ->and(count(ovhFixture('service-renew/400010001.json')['prices']))->toBe(2)
        ->and(ovhFixture('service-renew/400010003.json')['prices'])->toBe([])
        ->and(ovhFixture('service-renew/400010004.json')['prices'])->toBe([]);
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
