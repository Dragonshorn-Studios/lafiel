<?php

namespace Database\Factories\Domain\Costs\Models;

use App\Domain\Costs\Models\SubscriptionPreset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPreset>
 */
class SubscriptionPresetFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SubscriptionPreset>
     */
    protected $model = SubscriptionPreset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucwords(fake()->sentence(2, false));
        $vendor = fake()->company();

        return [
            // Same shape the form's generator produces: slug(vendor):slug(name).
            'key' => Str::slug($vendor).':'.Str::slug($name).'.'.fake()->unique()->numberBetween(1, 9999),
            'label' => sprintf('%s — %s ($%s/mo)', $vendor, $name, fake()->numberBetween(5, 50)),
            'vendor' => $vendor,
            'name' => $name,
            'category' => 'saas',
            'amount_minor' => fake()->numberBetween(500, 5000),
            'currency' => 'USD',
            'period' => 'monthly',
            'auto_renew' => true,
            'url' => fake()->url(),
            'source' => 'manual',
        ];
    }

    /**
     * A preset that came from a remote catalog import.
     */
    public function fromCatalog(?string $sourceUrl = null): static
    {
        return $this->state(fn (): array => [
            'source' => 'catalog',
            'source_url' => $sourceUrl ?? 'https://example.com/catalog.json',
        ]);
    }

    /**
     * A preset that came from the built-in seeder.
     */
    public function builtin(): static
    {
        return $this->state(fn (): array => [
            'source' => 'builtin',
        ]);
    }

    /**
     * A preset removed from the pickers but kept for history.
     */
    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
