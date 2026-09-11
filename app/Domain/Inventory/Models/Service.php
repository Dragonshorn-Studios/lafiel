<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;
use Database\Factories\Domain\Inventory\Models\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A canonical service. Provider-discovered services are unique by
 * (provider_account_id, external_id); manual services have neither.
 * Records are never hard-deleted.
 *
 * @property int $id
 * @property int|null $provider_account_id
 * @property string|null $external_id
 * @property string|null $provider_type
 * @property string $category
 * @property string $name
 * @property string|null $vendor
 * @property string|null $url
 * @property string|null $notes
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 * @property int $missing_complete_runs
 * @property ServiceLifecycle $lifecycle_state
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider_account_id', 'external_id', 'provider_type', 'category', 'name', 'vendor', 'url', 'notes', 'first_seen_at', 'last_seen_at', 'missing_complete_runs', 'lifecycle_state'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_complete_runs' => 'integer',
            'lifecycle_state' => ServiceLifecycle::class,
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
     * Coverage relation: cost items that charge for this service. A
     * package charge covering many services is one cost item with many
     * of these relations, never a copy per service.
     *
     * @return BelongsToMany<CostItem, $this>
     */
    public function costItems(): BelongsToMany
    {
        return $this->belongsToMany(CostItem::class, 'cost_item_services');
    }
}
