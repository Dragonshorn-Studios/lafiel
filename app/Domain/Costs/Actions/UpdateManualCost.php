<?php

namespace App\Domain\Costs\Actions;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Inventory\Models\Service;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateManualCost
{
    /**
     * Update a manual service and its open cost item. Price-affecting
     * changes (amount, currency, period) never rewrite the existing fact:
     * the open item is closed and a new item opens under the same logical
     * charge, so history keeps every price the service ever had.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Service $service, array $input): CostItem
    {
        $validated = $this->validate($input);

        return DB::transaction(function () use ($service, $validated): CostItem {
            $service->vendor = $validated['vendor'];
            $service->name = $validated['name'];
            $service->category = $validated['category'];
            $service->url = $validated['url'];
            $service->save();

            $open = $this->openCostItem($service);
            $changeDate = $validated['price_changed'] ? $this->now()->startOfDay() : $open->valid_from->startOfDay();

            if ($validated['price_changed']) {
                $open->valid_to = $changeDate->subDay()->endOfDay();
                $open->save();
            }

            $costItem = $validated['price_changed'] ? $service->costItems()->create([
                'identity_key' => sprintf('manual:service:%d:from:%s', $service->id, $changeDate->format('Y-m-d')),
                'logical_charge_key' => $open->logical_charge_key,
                'source_kind' => 'manual',
                'charge_kind' => $validated['period'] === Period::OneTime ? ChargeKind::OneTime : ChargeKind::RecurringFixed,
                'period' => $validated['period'],
                'amount_minor' => $validated['amount']?->amountMinor,
                'currency' => $validated['amount']?->currency,
                'amount_state' => $validated['amount'] === null ? 'unknown' : 'known',
                'evidence_state' => 'manual',
                'valid_from' => $changeDate,
                'valid_to' => $validated['valid_to'] ?? null,
                'observed_at' => $this->now(),
                'notes' => $validated['notes'],
            ]) : tap($open)->update([
                'valid_from' => $validated['valid_from'],
                'valid_to' => $validated['valid_to'] ?? null,
                'notes' => $validated['notes'],
            ]);

            $renewal = Renewal::query()->firstOrNew(['cost_item_id' => $open->id]);

            if ($validated['price_changed'] && $renewal->exists) {
                $renewal->cost_item_id = $costItem->id;
            }

            if ($validated['renews_at'] !== null) {
                $renewal->renews_at = $validated['renews_at'];
                $renewal->auto_renew = $validated['auto_renew'];
                $renewal->save();
            } elseif ($renewal->exists) {
                $renewal->delete();
            }

            return $costItem;
        });
    }

    private function openCostItem(Service $service): CostItem
    {
        $open = CostItem::query()
            ->where('logical_charge_key', sprintf('manual:service:%d', $service->id))
            ->whereNull('valid_to')
            ->orderByDesc('valid_from')
            ->first();

        if ($open === null) {
            $open = CostItem::query()
                ->where('logical_charge_key', sprintf('manual:service:%d', $service->id))
                ->orderByDesc('valid_from')
                ->firstOrFail();
        }

        return $open;
    }

    private function now(): CarbonImmutable
    {
        return new CarbonImmutable;
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
            'currency' => ['required', 'string', 'size:3'],
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
