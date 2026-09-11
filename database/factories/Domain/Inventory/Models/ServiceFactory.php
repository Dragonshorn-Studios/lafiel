<?php

namespace Database\Factories\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Providers\Models\ProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Service>
     */
    protected $model = Service::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_account_id' => null,
            'external_id' => null,
            'provider_type' => null,
            'category' => fake()->randomElement(['compute', 'storage', 'network', 'license', 'saas']),
            'name' => fake()->words(asText: true),
            'vendor' => null,
            'url' => null,
            'notes' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'missing_complete_runs' => 0,
            'lifecycle_state' => ServiceLifecycle::Active,
        ];
    }

    /**
     * A service discovered by a provider account, with a stable external id.
     */
    public function discovered(?ProviderAccount $account = null): static
    {
        return $this->state(fn (): array => [
            'provider_account_id' => $account === null ? ProviderAccount::factory() : $account->id,
            'external_id' => fake()->unique()->regexify('[a-z0-9-]{12}'),
            'provider_type' => fake()->randomElement(['vps', 'domain', 'object-storage', 'ip']),
        ]);
    }
}
