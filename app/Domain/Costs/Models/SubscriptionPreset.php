<?php

namespace App\Domain\Costs\Models;

use App\Domain\Costs\Enums\Period;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Domain\Costs\Models\SubscriptionPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One plan in the subscription library: a prefilled starting point
 * for a recurring manual cost. Catalog data only — editing or
 * archiving a preset never mutates cost items already created from it.
 *
 * The string `key` is the stable identity shared by four producers
 * (the built-in seeder, the form's generator, the import, and a
 * hand-typed key). It is deliberately a free string, not an enum.
 * Re-seeding never overwrites an existing key; the explicit,
 * confirmed catalog import is the one path that does.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string $vendor
 * @property string $name
 * @property string $category
 * @property int $amount_minor
 * @property string $currency
 * @property Period $period
 * @property bool $auto_renew
 * @property string|null $url
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'label', 'vendor', 'name', 'category', 'amount_minor', 'currency', 'period', 'auto_renew', 'url', 'archived_at'])]
class SubscriptionPreset extends Model
{
    /** @use HasFactory<SubscriptionPresetFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'auto_renew' => 'boolean',
            'archived_at' => 'datetime',
            'period' => Period::class,
        ];
    }

    /**
     * The plan's price as the shared Money value object.
     */
    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /**
     * Presets a cost can still be started from.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
