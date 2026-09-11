<?php

namespace Database\Factories\Domain\Providers\Models;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Providers\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ProviderCredential>
     */
    protected $model = ProviderCredential::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_account_id' => ProviderAccount::factory(),
            'payload' => ['key' => fake()->sha256(), 'secret' => fake()->password(24)],
            'schema_version' => 1,
            'fingerprint' => fake()->sha1(),
            'verified_at' => null,
        ];
    }

    /**
     * Indicate that the credentials have been verified.
     */
    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
        ]);
    }
}
