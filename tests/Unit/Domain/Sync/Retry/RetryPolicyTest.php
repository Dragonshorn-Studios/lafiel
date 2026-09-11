<?php

use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\TransientProviderException;
use App\Domain\Sync\Retry\RetryPolicy;
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;

/** Production defaults: 4 attempts, 500ms base, 15s cap. */
function budgetedPolicy(int $maxAttempts = 4): RetryPolicy
{
    return new RetryPolicy($maxAttempts, 500, 15000);
}

it('returns the operation result when transient failures recover', function () {
    Sleep::fake();

    $attempts = 0;

    $result = budgetedPolicy()->execute(function () use (&$attempts): string {
        $attempts++;

        return $attempts < 3
            ? throw new TransientProviderException('503 service unavailable')
            : 'batch';
    });

    expect($result)->toBe('batch')
        ->and($attempts)->toBe(3);

    // Ceiling doubles per attempt: 500ms, then 1000ms, each halved by jitter.
    Sleep::assertSlept(
        fn (CarbonInterval $sleep) => $sleep->totalMilliseconds >= 250 && $sleep->totalMilliseconds <= 500,
        1,
    );
    Sleep::assertSlept(
        fn (CarbonInterval $sleep) => $sleep->totalMilliseconds >= 500 && $sleep->totalMilliseconds <= 1000,
        1,
    );
});

it('exhausts the configured attempt budget before giving up', function () {
    Sleep::fake();

    $attempts = 0;

    try {
        budgetedPolicy(3)->execute(function () use (&$attempts): never {
            $attempts++;
            throw new TransientProviderException('429 too many requests');
        });

        $this->fail('Expected the transient exception to escape after the budget was exhausted.');
    } catch (TransientProviderException) {
        // expected
    }

    expect($attempts)->toBe(3);
});

it('never sleeps after the final transient attempt', function () {
    Sleep::fake();

    try {
        budgetedPolicy(3)->execute(fn (): never => throw new TransientProviderException('429 too many requests'));
    } catch (TransientProviderException) {
        // expected
    }

    Sleep::assertSleptTimes(2);
});

it('caps the backoff at the configured maximum delay', function () {
    Sleep::fake();

    $attempts = 0;

    try {
        // Without the cap, the third retry's ceiling would reach 40000ms.
        (new RetryPolicy(4, 10000, 10000))->execute(function () use (&$attempts): never {
            $attempts++;
            throw new TransientProviderException('503 service unavailable');
        });

        $this->fail('Expected the transient exception to escape after the budget was exhausted.');
    } catch (TransientProviderException) {
        // expected
    }

    Sleep::assertSleptTimes(3);
    Sleep::assertSlept(
        fn (CarbonInterval $sleep) => $sleep->totalMilliseconds >= 5000 && $sleep->totalMilliseconds <= 10000,
        3,
    );
});

it('does not retry invalid credentials', function () {
    Sleep::fake();

    $attempts = 0;

    try {
        budgetedPolicy()->execute(function () use (&$attempts): never {
            $attempts++;
            throw new InvalidCredentialsException('provider rejected the credentials');
        });
    } catch (InvalidCredentialsException) {
        // expected
    }

    expect($attempts)->toBe(1);
    Sleep::assertNeverSlept();
});

it('does not retry unexpected errors', function () {
    Sleep::fake();

    try {
        budgetedPolicy()->execute(fn (): never => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    Sleep::assertNeverSlept();
});
