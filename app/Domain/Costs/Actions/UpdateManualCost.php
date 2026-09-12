<?php

namespace App\Domain\Costs\Actions;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\History\TakeSnapshot;
use App\Domain\Inventory\Models\Service;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateManualCost
{
    public function __construct(private readonly TakeSnapshot $takeSnapshot) {}

    /**
     * Update a manual charge and the service it bills for. Price-affecting
     * changes (amount, currency, period) never rewrite the existing fact:
     * the open item is closed and a new item opens under the same logical
     * charge, so history keeps every price the charge ever had. Renewals
     * always follow the newest version of the charge.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the charge has already been ended
     */
    public function update(CostItem $requested, array $input): CostItem
    {
        $validated = $this->validate($input);

        $costItem = DB::transaction(function () use ($requested, $validated): CostItem {
            $open = $this->openVersion($requested);
            $service = $open->services()->first();

            if ($service !== null) {
                $service->vendor = $validated['vendor'];
                $service->name = $validated['name'];
                $service->category = $validated['category'];
                $service->url = $validated['url'];
                $service->save();
            }

            $changeDate = $validated['price_changed']
                ? new CarbonImmutable()->startOfDay()
                : $open->valid_from->startOfDay();

            if ($validated['price_changed']) {
                $open->valid_to = $changeDate->subDay()->endOfDay();
                $open->save();
            }

            $costItem = $validated['price_changed']
                ? $this->openNewVersion($open, $changeDate, $validated)
                : tap($open)->update([
                    'valid_from' => $validated['valid_from'],
                    'valid_to' => $validated['valid_to'],
                    'notes' => $validated['notes'],
                ]);

            $this->updateRenewal($open, $costItem, $validated);

            return $costItem;
        });

        // Inputs may have changed: capture; the checksum dedupes when
        // the stored output would not change.
        $this->takeSnapshot->capture();

        return $costItem;
    }

    /**
     * The newest version of the charge must still be open. A stale client
     * holding an ended item is refused instead of resurrecting history.
     */
    private function openVersion(CostItem $requested): CostItem
    {
        $open = CostItem::query()
            ->where('logical_charge_key', $requested->logical_charge_key)
            ->whereNull('valid_to')
            ->first();

        if ($open === null || $open->isNot($requested)) {
            throw ValidationException::withMessages([
                'cost' => __('This cost has already been ended or changed elsewhere. Reload and try again.'),
            ]);
        }

        return $open;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function openNewVersion(CostItem $open, CarbonImmutable $changeDate, array $validated): CostItem
    {
        // Versions of one charge may start on the same day (a price fixed
        // right after creation), so the identity key carries a version
        // suffix once the plain date key is taken.
        $base = sprintf('%s:from:%s', $open->logical_charge_key, $changeDate->format('Y-m-d'));
        $identity = $base;
        $version = 1;

        while (CostItem::query()->where('identity_key', $identity)->exists()) {
            $version++;
            $identity = $base.':v'.$version;
        }

        $costItem = CostItem::create([
            'identity_key' => $identity,
            'logical_charge_key' => $open->logical_charge_key,
            'source_kind' => 'manual',
            'charge_kind' => $validated['period'] === Period::OneTime ? ChargeKind::OneTime : ChargeKind::RecurringFixed,
            'period' => $validated['period'],
            'amount_minor' => $validated['amount']?->amountMinor,
            'currency' => $validated['amount']?->currency,
            'amount_state' => $validated['amount'] === null ? 'unknown' : 'known',
            'evidence_state' => 'manual',
            'valid_from' => $changeDate,
            'valid_to' => $validated['valid_to'],
            'observed_at' => new CarbonImmutable,
            'notes' => $validated['notes'],
        ]);

        // The new version covers the same services as the closed one.
        $costItem->services()->attach($open->services()->pluck('services.id'));

        return $costItem;
    }

    /**
     * Renewals always point at the newest version of the charge. On a
     * price change every renewal on the closed item moves to the new one
     * before the user's edit is applied, so a first-time renewal cannot
     * land on a closed item and an existing one cannot be orphaned.
     *
     * @param  array<string, mixed>  $validated
     */
    private function updateRenewal(CostItem $closed, CostItem $open, array $validated): void
    {
        if ($open->isNot($closed)) {
            Renewal::query()->where('cost_item_id', $closed->id)->update(['cost_item_id' => $open->id]);
        }

        $renewal = Renewal::query()->firstOrNew(['cost_item_id' => $open->id]);

        // Without an explicit date, auto-renew assumes the next
        // occurrence one period after the charge's current start.
        $renewsAt = $validated['renews_at']
            ?? ($validated['auto_renew'] ? $validated['period']->advance($open->valid_from->startOfDay()) : null);

        if ($renewsAt !== null) {
            $renewal->renews_at = $renewsAt;
            $renewal->auto_renew = $validated['auto_renew'];
            $renewal->save();

            return;
        }

        if ($renewal->exists) {
            $renewal->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $validated = Validator::make($input, [
            'vendor' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'unknown_amount' => ['nullable', 'boolean'],
            'amount' => [
                Rule::requiredIf(! filter_var($input['unknown_amount'] ?? false, FILTER_VALIDATE_BOOL)),
                'nullable', 'string', 'regex:/^\s*-?\d+(?:\.\d{1,2})?\s*$/',
            ],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'period' => ['required', Rule::enum(Period::class)],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'renews_at' => ['nullable', 'date'],
            'auto_renew' => ['nullable', 'boolean'],
            'url' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string'],
            'price_changed' => ['nullable', 'boolean'],
        ])->validate();

        $unknown = filter_var($validated['unknown_amount'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            ...$validated,
            'period' => Period::from($validated['period']),
            'amount' => $unknown ? null : Money::ofString($validated['amount'], $validated['currency']),
            'valid_from' => new CarbonImmutable($validated['valid_from']),
            'valid_to' => isset($validated['valid_to']) ? new CarbonImmutable($validated['valid_to']) : null,
            'renews_at' => isset($validated['renews_at']) ? new CarbonImmutable($validated['renews_at']) : null,
            'auto_renew' => filter_var($validated['auto_renew'] ?? false, FILTER_VALIDATE_BOOL),
            'price_changed' => filter_var($validated['price_changed'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }
}
