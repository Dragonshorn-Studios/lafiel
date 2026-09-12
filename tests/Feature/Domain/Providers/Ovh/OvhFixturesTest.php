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

it('covers the three required cases without secret material', function () {
    $runA = ovhFixture('service-run-a.json');
    $runB = ovhFixture('service-run-b.json');

    // Missing on the next complete run: present in run A, absent in run B.
    expect($runA)->toContain('ip-synthetic-01')
        ->and($runB)->not->toContain('ip-synthetic-01');

    // Ordinary priced service + unknown price case exist as service captures.
    expect(file_exists(ovhFixtureDir().'/service/vps-synthetic-01.json'))->toBeTrue()
        ->and(file_exists(ovhFixtureDir().'/service/domain-zone-synthetic-01.json'))->toBeTrue();
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
