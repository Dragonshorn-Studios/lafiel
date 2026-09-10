<?php

namespace App\Domain\Sync\Models;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Enums\SyncStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Domain\Sync\Models\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One synchronization run. Carries counts and sanitized summaries
 * only; raw provider payloads are never stored.
 *
 * @property int $id
 * @property int $provider_account_id
 * @property string $trigger
 * @property SyncStatus $status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property array<string, mixed>|null $counts
 * @property array<string, mixed>|null $summary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider_account_id', 'trigger', 'status', 'started_at', 'finished_at', 'counts', 'summary'])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts' => 'array',
            'summary' => 'array',
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
