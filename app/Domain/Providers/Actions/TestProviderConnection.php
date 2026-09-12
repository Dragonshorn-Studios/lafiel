<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Dtos\ConnectionCheck;
use App\Domain\Providers\Dtos\SyncContext;
use App\Domain\Providers\Exceptions\InvalidCredentialsException;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Probe one stored credential through its provider adapter — the same
 * `validateCredentials` path a sync takes, run once with no retry. A
 * rejection is a distinct state and never retried; transient failures
 * report unreachable, because this runs interactively from a settings
 * page where the user can simply press the button again. The sync
 * orchestrator owns the bounded-retry policy for its own runs.
 */
class TestProviderConnection
{
    public function __construct(private readonly AdapterRegistry $adapters) {}

    public function check(ProviderAccount $account, ProviderCredential $credential): ConnectionCheck
    {
        try {
            $payload = $credential->readablePayload();
        } catch (DecryptException) {
            // A rotated APP_KEY makes the stored payload unreadable; it
            // is the same human-fixable condition as a rejected probe.
            return ConnectionCheck::rejected(__('Stored credentials are unreadable. Replace them and test again.'));
        }

        $context = new SyncContext($account, $payload, now());

        try {
            $check = $this->adapters->for($account->provider_key)->validateCredentials($context);
        } catch (InvalidCredentialsException $exception) {
            return ConnectionCheck::rejected($exception->getMessage());
        } catch (ProviderException $exception) {
            return ConnectionCheck::unreachable($exception->getMessage());
        }

        if (! $check->valid) {
            return ConnectionCheck::rejected($check->warning ?? __('The provider rejected the credentials.'));
        }

        return ConnectionCheck::connected();
    }
}
