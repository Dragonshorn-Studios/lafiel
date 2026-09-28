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
 * `source` marks the row's origin: `builtin` (the seeder's
 * transcriptions), `manual` (hand-added on the Plans page), or
 * `catalog` (imported from a remote catalog, with `source_url` kept
 * as provenance). Once a catalog import exists, the built-ins are a
 * crutch: `scopeVisible` hides them everywhere by default.
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
 * @property string $source
 * @property string|null $source_url
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'label', 'vendor', 'name', 'category', 'amount_minor', 'currency', 'period', 'auto_renew', 'url', 'source', 'source_url', 'archived_at'])]
class SubscriptionPreset extends Model
{
    /** Row origin: the seeder's transcriptions. */
    public const SOURCE_BUILTIN = 'builtin';

    /** Row origin: hand-added on the Plans page. */
    public const SOURCE_MANUAL = 'manual';

    /** Row origin: imported from a remote catalog (source_url has provenance). */
    public const SOURCE_CATALOG = 'catalog';

    /** @var list<string> the closed `source` dimension, mirrored by a column CHECK */
    public const SOURCES = [
        self::SOURCE_BUILTIN,
        self::SOURCE_MANUAL,
        self::SOURCE_CATALOG,
    ];

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
     * Whether a remote catalog import is in use — at least one active
     * (non-archived) catalog row exists — which demotes the built-in
     * transcriptions to hidden crutches. Archiving every catalog row
     * releases the built-ins again: suppression must have an exit.
     */
    public static function catalogInUse(): bool
    {
        return static::query()
            ->where('source', self::SOURCE_CATALOG)
            ->whereNull('archived_at')
            ->exists();
    }

    /**
     * Presets a cost can still be started from: active, and — once a
     * catalog import exists — not one of the built-in transcriptions.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        $query->whereNull('archived_at');

        if (self::catalogInUse()) {
            $query->where('source', '!=', self::SOURCE_BUILTIN);
        }

        return $query;
    }
}
