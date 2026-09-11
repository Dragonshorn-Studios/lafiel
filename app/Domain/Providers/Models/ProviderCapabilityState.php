<?php

namespace App\Domain\Providers\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Domain\Providers\Models\ProviderCapabilityStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $provider_account_id
 * @property string $capability_key
 * @property bool $supported
 * @property bool $healthy
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_observed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider_account_id', 'capability_key', 'supported', 'healthy', 'last_attempt_at', 'last_success_at', 'last_observed_at'])]
class ProviderCapabilityState extends Model
{
    /** @use HasFactory<ProviderCapabilityStateFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supported' => 'boolean',
            'healthy' => 'boolean',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_observed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProviderAccount, $this>
     */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }
}
