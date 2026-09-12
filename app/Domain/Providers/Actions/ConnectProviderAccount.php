<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Connect one provider account. The credentials are stored encrypted
 * and unverified: the first connection test or sync proves them.
 * Several accounts per provider may coexist — billing accounts are
 * independent. The payload shape comes from the provider's credential
 * schema; the provider itself is a plain string key shared with the
 * adapter registry.
 *
 * @throws ValidationException
 */
class ConnectProviderAccount
{
    public function __construct(private readonly CredentialSchemas $schemas) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function connect(string $providerKey, array $input): ProviderAccount
    {
        $schema = $this->schemas->for($providerKey);

        $validated = Validator::make($input, [
            'display_name' => ['required', 'string', 'max:255'],
            ...$schema::rules(),
        ])->validate();

        $payload = $schema::payload($validated);

        return DB::transaction(function () use ($providerKey, $schema, $validated, $payload): ProviderAccount {
            $account = ProviderAccount::create([
                'provider_key' => $providerKey,
                'display_name' => $validated['display_name'],
                'enabled' => true,
            ]);

            ProviderCredential::create([
                'provider_account_id' => $account->id,
                'payload' => $payload,
                'schema_version' => $schema::SCHEMA_VERSION,
            ]);

            return $account;
        });
    }
}
