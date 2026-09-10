<?php

namespace Database\Factories\Domain\Costs\Models;

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\AmountState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Costs\Models\CostItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CostItem>
 */
class CostItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CostItem>
     */
    protected $model = CostItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $identityKey = Str::uuid()->toString();

        return [
            'identity_key' => $identityKey,
            'logical_charge_key' => $identityKey,
            'source_kind' => SourceKind::Manual,
            'charge_kind' => ChargeKind::RecurringFixed,
            'period' => Period::Monthly,
            'amount_minor' => fake()->numberBetween(100, 100000),
            'currency' => 'PLN',
            'amount_state' => AmountState::Known,
            'evidence_state' => EvidenceState::Manual,
            'tax_basis' => TaxBasis::Unknown,
            'allocation_state' => AllocationState::Direct,
            'is_manual_override' => false,
            'valid_from' => today(),
            'valid_to' => null,
            'observed_at' => now(),
            'source_ref' => null,
            'notes' => null,
        ];
    }

    /**
     * An item with an unknown amount: it never joins the known sum.
     */
    public function unknownAmount(): static
    {
        return $this->state(fn (): array => [
            'amount_minor' => null,
            'currency' => null,
            'amount_state' => AmountState::Unknown,
        ]);
    }

    /**
     * A quote from a provider subscription or renewal list.
     */
    public function subscriptionQuote(): static
    {
        return $this->state(fn (): array => [
            'source_kind' => SourceKind::Subscription,
            'evidence_state' => EvidenceState::Quote,
        ]);
    }

    /**
     * An actual from a provider invoice.
     */
    public function invoiceActual(): static
    {
        return $this->state(fn (): array => [
            'source_kind' => SourceKind::Invoice,
            'evidence_state' => EvidenceState::Actual,
        ]);
    }

    /**
     * An actual from metered usage.
     */
    public function usageActual(): static
    {
        return $this->state(fn (): array => [
            'source_kind' => SourceKind::Usage,
            'evidence_state' => EvidenceState::Actual,
            'charge_kind' => ChargeKind::Usage,
        ]);
    }

    /**
     * A conscious manual override that outranks stronger evidence.
     */
    public function manualOverride(): static
    {
        return $this->state(fn (): array => [
            'is_manual_override' => true,
        ]);
    }

    /**
     * A shared package charge whose allocation is not resolved yet.
     */
    public function sharedUnallocated(): static
    {
        return $this->state(fn (): array => [
            'allocation_state' => AllocationState::SharedUnallocated,
        ]);
    }

    /**
     * An ended item: excluded from projections after valid_to.
     *
     * @param  CarbonImmutable|string  $endedAt
     */
    public function ended($endedAt = 'yesterday'): static
    {
        return $this->state(fn (): array => [
            'valid_to' => $endedAt,
        ]);
    }
}
