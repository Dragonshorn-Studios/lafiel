<?php

namespace App\Domain\Providers\Ovh;

use App\Domain\Providers\Dtos\ConnectionCheck;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Sync\Retry\RetryPolicy;

/**
 * Connection test for one OVH credential payload: an authenticated GET
 * of the account identity (`GET /me`), the smallest read-only probe.
 * A rejection surfaces as a distinct `rejected` state and is never
 * retried — bad credentials are permanent until a human replaces them.
 * Only transient failures go through the bounded retry policy, matching
 * how the sync orchestrator validates credentials.
 */
final class TestOvhConnection
{
    public function __construct(private readonly RetryPolicy $retry) {}

    public function check(OvhApi $api): ConnectionCheck
    {
        try {
            $this->retry->execute(fn (): mixed => $api->get('/me'));
        } catch (InvalidCredentialsException $exception) {
            return ConnectionCheck::rejected($exception->getMessage());
        } catch (ProviderException $exception) {
            return ConnectionCheck::unreachable($exception->getMessage());
        }

        return ConnectionCheck::connected();
    }
}
