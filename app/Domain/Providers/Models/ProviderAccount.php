<?php

namespace App\Domain\Providers\Models;

use App\Domain\Inventory\Models\Service;
use App\Domain\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;
use Database\Factories\Domain\Providers\Models\ProviderAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $provider_key
 * @property string $display_name
 * @property string|null $region
 * @property bool $enabled
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider_key', 'display_name', 'region', 'enabled', 'last_attempt_at', 'last_success_at'])]
class ProviderAccount extends Model
{
    /** @use HasFactory<ProviderAccountFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ProviderCapabilityState, $this>
     */
    public function capabilityStates(): HasMany
    {
        return $this->hasMany(ProviderCapabilityState::class);
    }

    /**
     * @return HasMany<ProviderCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
