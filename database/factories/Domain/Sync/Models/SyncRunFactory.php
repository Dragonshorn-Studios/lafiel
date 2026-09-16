<?php

namespace Database\Factories\Domain\Sync\Models;

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Enums\SyncStage;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SyncRun>
     */
    protected $model = SyncRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_account_id' => ProviderAccount::factory(),
            'trigger' => fake()->randomElement(['schedule', 'manual', 'setup']),
            'status' => SyncStatus::Succeeded,
            'stage' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'counts' => null,
            'summary' => null,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => SyncStatus::Queued,
            'stage' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => SyncStatus::Running,
            'stage' => SyncStage::Inventory,
            'finished_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => SyncStatus::Failed,
            'stage' => SyncStage::Credentials,
            'summary' => ['error' => 'credentials are invalid'],
        ]);
    }
}
