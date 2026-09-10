<?php

namespace Database\Factories\Domain\Providers\Models;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCapabilityState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCapabilityState>
 */
class ProviderCapabilityStateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ProviderCapabilityState>
     */
    protected $model = ProviderCapabilityState::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_account_id' => ProviderAccount::factory(),
            'capability_key' => fake()->randomElement(['inventory', 'renewal_quotes', 'subscriptions', 'usage', 'invoices']),
            'supported' => true,
            'healthy' => true,
            'last_attempt_at' => null,
            'last_success_at' => null,
            'last_observed_at' => null,
        ];
    }
}
