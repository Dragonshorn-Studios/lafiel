<?php

namespace App\Domain\Providers\Actions;

use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Replace a provider account's credentials. A new credential row is
 * created and the old one kept, so the history of what was verified
 * when survives; the orchestrator always reads the latest row. The new
 * row starts unverified — the next connection test or sync proves it.
 * Secret fields are always re-entered; stored values are never
 * rendered back into the form.
 *
 * @throws ValidationException
 */
class UpdateProviderCredentials
{
    public function __construct(private readonly CredentialSchemas $schemas) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(ProviderAccount $account, array $input): ProviderCredential
    {
        $schema = $this->schemas->for($account->provider_key);

        $validated = Validator::make($input, [
            'display_name' => ['required', 'string', 'max:255'],
            ...$schema::rules(),
        ])->validate();

        $payload = $schema::payload($validated);

        return DB::transaction(function () use ($account, $schema, $validated, $payload): ProviderCredential {
            $account->display_name = $validated['display_name'];
            $account->save();

            return ProviderCredential::create([
                'provider_account_id' => $account->id,
                'payload' => $payload,
                'schema_version' => $schema::SCHEMA_VERSION,
            ]);
        });
    }
}
