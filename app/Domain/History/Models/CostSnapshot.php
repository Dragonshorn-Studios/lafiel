<?php

namespace App\Domain\History\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Domain\History\Models\CostSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A captured snapshot of projected costs. Historical output only,
 * never a second source of truth: it stores the totals, completeness
 * counters, calculation version, and FX values used at capture time.
 *
 * @property int $id
 * @property CarbonImmutable $snapshot_date
 * @property array<string, mixed> $totals
 * @property array<string, mixed> $completeness
 * @property string $calculation_version
 * @property array<string, mixed>|null $fx_used
 * @property array<string, mixed> $breakdown
 * @property string|null $input_checksum
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['snapshot_date', 'totals', 'completeness', 'calculation_version', 'fx_used', 'breakdown', 'input_checksum'])]
class CostSnapshot extends Model
{
    /** @use HasFactory<CostSnapshotFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'totals' => 'array',
            'completeness' => 'array',
            'fx_used' => 'array',
            'breakdown' => 'array',
        ];
    }
}
