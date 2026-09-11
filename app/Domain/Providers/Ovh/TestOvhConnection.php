<?php

namespace App\Domain\Providers\Ovh;

use App\Domain\Providers\Dtos\ConnectionCheck;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;

/**
 * Connection test for one OVH credential payload: an authenticated GET
 * of the account identity (`GET /me`), the smallest read-only probe,
 * run once. A rejection surfaces as a distinct `rejected` state and is
 * never retried — bad credentials are permanent until a human replaces
 * them — and transient failures report unreachable without retrying,
 * because this runs interactively from a settings page where the user
 * can simply press the button again. The sync orchestrator owns the
 * bounded-retry policy for its own runs.
 */
final class TestOvhConnection
{
    public function check(OvhApi $api): ConnectionCheck
    {
        try {
            $api->get('/me');
        } catch (InvalidCredentialsException $exception) {
            return ConnectionCheck::rejected($exception->getMessage());
        } catch (ProviderException $exception) {
            return ConnectionCheck::unreachable($exception->getMessage());
        }

        return ConnectionCheck::connected();
    }
}
