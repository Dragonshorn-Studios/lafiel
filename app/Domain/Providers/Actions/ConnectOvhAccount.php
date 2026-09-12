<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use App\Domain\Providers\Ovh\OvhCredentialSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Connect one OVH account. The credentials are stored encrypted and
 * unverified: the first connection test or sync proves them. Several
 * OVH accounts may coexist — billing accounts are independent.
 *
 * @throws ValidationException
 */
class ConnectOvhAccount
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function connect(array $input): ProviderAccount
    {
        $validated = Validator::make($input, [
            'display_name' => ['required', 'string', 'max:255'],
            ...OvhCredentialSchema::rules(),
        ])->validate();

        $payload = OvhCredentialSchema::payload($validated);

        return DB::transaction(function () use ($validated, $payload): ProviderAccount {
            $account = ProviderAccount::create([
                'provider_key' => 'ovh',
                'display_name' => $validated['display_name'],
                'enabled' => true,
            ]);

            ProviderCredential::create([
                'provider_account_id' => $account->id,
                'payload' => $payload,
                'schema_version' => OvhCredentialSchema::SCHEMA_VERSION,
            ]);

            return $account;
        });
    }
}
