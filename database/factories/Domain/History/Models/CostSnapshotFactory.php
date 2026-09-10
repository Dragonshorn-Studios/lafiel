<?php

namespace Database\Factories\Domain\History\Models;

use App\Domain\History\Models\CostSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostSnapshot>
 */
class CostSnapshotFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CostSnapshot>
     */
    protected $model = CostSnapshot::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'snapshot_date' => today(),
            'totals' => ['PLN' => ['monthly_minor' => 18742, 'annual_minor' => 224904]],
            'completeness' => ['unknown' => 0, 'estimate' => 0, 'stale' => 0, 'shared_unallocated' => 0],
            'calculation_version' => 'v1',
            'fx_used' => null,
            'breakdown' => [],
            'input_checksum' => fake()->sha256(),
        ];
    }
}
