<?php

namespace App\Domain\Providers\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Domain\Providers\Models\ProviderCredentialFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encrypted provider credentials. The payload never appears in API
 * resources, logs, exceptions, fixtures, or docs; restoring it after a
 * backup requires the same APP_KEY that encrypted it.
 *
 * @property int $id
 * @property int $provider_account_id
 * @property array<string, mixed> $payload
 * @property int $schema_version
 * @property string|null $fingerprint
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider_account_id', 'payload', 'schema_version', 'fingerprint', 'verified_at'])]
#[Hidden(['payload'])]
class ProviderCredential extends Model
{
    /** @use HasFactory<ProviderCredentialFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'schema_version' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProviderAccount, $this>
     */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    /**
     * The decrypted payload. Exists as a method so callers can declare
     * the decrypt failure mode — reading the attribute directly hides
     * it from both static analysis and intent.
     *
     * @return array<string, mixed>
     *
     * @throws DecryptException when
     *                          the APP_KEY cannot
     *                          decrypt this payload
     */
    public function readablePayload(): array
    {
        return $this->payload;
    }
}
