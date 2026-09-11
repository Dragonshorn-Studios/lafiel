<?php

namespace App\Domain\Costs\Models;

use App\Domain\Costs\Enums\AllocationState;
use App\Domain\Costs\Enums\AmountState;
use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\EvidenceState;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Enums\SourceKind;
use App\Domain\Costs\Enums\TaxBasis;
use App\Domain\Inventory\Models\Service;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Domain\Costs\Models\CostItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One cost fact. May cover zero, one, or many services. Independent
 * dimensions (source, charge kind, amount state, evidence, tax basis,
 * allocation) are never collapsed into one state.
 *
 * @property int $id
 * @property string $identity_key
 * @property string $logical_charge_key
 * @property SourceKind $source_kind
 * @property ChargeKind $charge_kind
 * @property Period $period
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property AmountState $amount_state
 * @property EvidenceState $evidence_state
 * @property TaxBasis $tax_basis
 * @property AllocationState $allocation_state
 * @property bool $is_manual_override
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable|null $observed_at
 * @property string|null $source_ref
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['identity_key', 'logical_charge_key', 'source_kind', 'charge_kind', 'period', 'amount_minor', 'currency', 'amount_state', 'evidence_state', 'tax_basis', 'allocation_state', 'is_manual_override', 'valid_from', 'valid_to', 'observed_at', 'source_ref', 'notes'])]
class CostItem extends Model
{
    /** @use HasFactory<CostItemFactory> */
    use HasFactory;

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_kind' => SourceKind::class,
            'charge_kind' => ChargeKind::class,
            'period' => Period::class,
            'amount_state' => AmountState::class,
            'evidence_state' => EvidenceState::class,
            'tax_basis' => TaxBasis::class,
            'allocation_state' => AllocationState::class,
            'is_manual_override' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'observed_at' => 'datetime',
        ];
    }

    /**
     * The known amount, or null for unknown amounts. Unknown amounts
     * stay outside the known sum and are counted explicitly.
     */
    public function money(): ?Money
    {
        if ($this->amount_state !== AmountState::Known || $this->amount_minor === null || $this->currency === null) {
            return null;
        }

        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /**
     * Whether this item supersedes another with the same logical charge,
     * given the canonical evidence precedence: invoice actual > usage
     * actual > subscription or renewal quote > manual, with a conscious
     * manual override winning only when explicitly enabled. Actuals rank
     * by their source; quotes and estimates rank together regardless of
     * source, so an invoice-sourced quote never beats a usage actual.
     * Ties break on observation time.
     */
    public function outranks(self $other): bool
    {
        $thisRank = $this->precedenceRank();
        $otherRank = $other->precedenceRank();

        if ($thisRank !== $otherRank) {
            return $thisRank > $otherRank;
        }

        return $this->observed_at > $other->observed_at;
    }

    private function precedenceRank(): int
    {
        if ($this->is_manual_override) {
            return 4;
        }

        if ($this->source_kind === SourceKind::Manual) {
            return 0;
        }

        // Only actual evidence earns its source's full strength; quotes
        // and estimates rank at the quote level whatever their source.
        if ($this->evidence_state !== EvidenceState::Actual) {
            return 1;
        }

        return match ($this->source_kind) {
            SourceKind::Invoice => 3,
            SourceKind::Usage => 2,
            SourceKind::Subscription, SourceKind::RenewalQuote => 1,
        };
    }

    /**
     * Coverage relation: the services this cost item charges for.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'cost_item_services');
    }

    /**
     * @return HasOne<Renewal, $this>
     */
    public function renewal(): HasOne
    {
        return $this->hasOne(Renewal::class);
    }
}
