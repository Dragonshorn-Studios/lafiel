<?php

namespace Tests\Feature\Domain\Providers\Cloudflare;

use Illuminate\Support\Facades\File;

it('ships only parseable JSON fixtures', function () {
    $jsonFiles = array_values(array_filter(
        File::allFiles(cloudflareFixtureDir()),
        fn (\SplFileInfo $file): bool => $file->getExtension() === 'json',
    ));

    expect($jsonFiles)->not->toBeEmpty();

    foreach ($jsonFiles as $file) {
        cloudflareFixture(str_replace(cloudflareFixtureDir().'/', '', $file->getPathname()));
    }

    expect(true)->toBeTrue();
});

it('covers the three required cases without secret material', function () {
    // Ordinary priced subscriptions: workers (account) and pro (zone).
    $accountSubscriptions = cloudflareFixture('account-subscriptions.json');
    $zoneSubscriptions = cloudflareFixture('zone-subscriptions-a.json');

    expect(collect($accountSubscriptions['result'])->pluck('id'))->toContain('sub-workers-synthetic-01')
        ->and(collect($zoneSubscriptions['result'])->pluck('id'))->toContain('sub-pro-synthetic-01');

    // Unknown price case: a subscription with no price component.
    $unknown = collect(cloudflareFixture('account-subscriptions.json')['result'])
        ->firstWhere('id', 'sub-unknown-price-synthetic-02');

    expect($unknown)->toHaveKey('rate_plan')
        ->and($unknown['rate_plan'])->not->toHaveKey('components');

    // Free-plan zero: a known zero is a price, not an absence.
    $free = collect(cloudflareFixture('zone-subscriptions-b.json')['result'])
        ->firstWhere('id', 'sub-free-synthetic-02');

    expect($free['rate_plan']['components'][0]['price'])->toBe('0.00');
});

it('carries no credential-shaped value anywhere', function () {
    foreach (File::allFiles(cloudflareFixtureDir()) as $file) {
        $contents = $file->getContents();

        expect($contents)->not->toContain(cloudflarePayload()['api_token'])
            ->not->toMatch('/api_token"\s*:\s*"(?!\[)CF/');
    }
});
