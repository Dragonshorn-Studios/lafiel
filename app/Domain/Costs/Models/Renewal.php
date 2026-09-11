<?php

namespace App\Domain\Costs\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Domain\Costs\Models\RenewalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A renewal pointer. References a cost item; amount and currency are
 * never duplicated here.
 *
 * @property int $id
 * @property int $cost_item_id
 * @property CarbonImmutable $renews_at
 * @property bool $auto_renew
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['cost_item_id', 'renews_at', 'auto_renew'])]
class Renewal extends Model
{
    /** @use HasFactory<RenewalFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'renews_at' => 'date',
            'auto_renew' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CostItem, $this>
     */
    public function costItem(): BelongsTo
    {
        return $this->belongsTo(CostItem::class);
    }
}
