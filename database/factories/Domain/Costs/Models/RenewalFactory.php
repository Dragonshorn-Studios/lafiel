<?php

namespace Database\Factories\Domain\Costs\Models;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Renewal>
 */
class RenewalFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Renewal>
     */
    protected $model = Renewal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cost_item_id' => CostItem::factory(),
            'renews_at' => fake()->dateTimeBetween('now', '+1 year'),
            'auto_renew' => false,
        ];
    }

    /**
     * A renewal that happens automatically.
     */
    public function automatic(): static
    {
        return $this->state(fn (): array => [
            'auto_renew' => true,
        ]);
    }
}
