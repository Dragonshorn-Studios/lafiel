<?php

namespace Database\Factories\Domain\Providers\Models;

use App\Domain\Providers\Models\ProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderAccount>
 */
class ProviderAccountFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ProviderAccount>
     */
    protected $model = ProviderAccount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_key' => fake()->randomElement(['ovh', 'contabo', 'hetzner', 'cloudflare']),
            'display_name' => fake()->company(),
            'region' => fake()->randomElement(['eu-west', 'eu-central', 'us-east']),
            'enabled' => true,
            'last_attempt_at' => null,
            'last_success_at' => null,
        ];
    }

    /**
     * Indicate that the account has completed a successful attempt.
     */
    public function synced(): static
    {
        return $this->state(fn (): array => [
            'last_attempt_at' => now(),
            'last_success_at' => now(),
        ]);
    }

    /**
     * Indicate that the account is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'enabled' => false,
        ]);
    }
}
