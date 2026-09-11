<?php

namespace App\Domain\Costs\Actions;

use App\Domain\Costs\Enums\ChargeKind;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Costs\Models\Renewal;
use App\Domain\Inventory\Enums\ServiceLifecycle;
use App\Domain\Inventory\Models\Service;
use App\Domain\Support\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateManualCost
{
    /**
     * Create one manual charge with optional renewal and coverage. Every
     * charge carries its own logical charge key, so several independent
     * charges on the same service (a base fee plus an add-on, or two
     * overlays) coexist and each is counted once by the projection.
     * When `covers_service_id` points at an existing service, no new
     * service is created: the charge overlays that service through the
     * same mechanism a provider-discovered service would use.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): CostItem
    {
        $validated = $this->validate($input);

        return DB::transaction(function () use ($validated): CostItem {
            $service = $this->resolveService($validated);
            $validFrom = $validated['valid_from']->startOfDay();
            $chargeKey = sprintf('manual:charge:%s', Str::uuid()->toString());

            $costItem = $service->costItems()->create([
                'identity_key' => sprintf('%s:from:%s', $chargeKey, $validFrom->format('Y-m-d')),
                'logical_charge_key' => $chargeKey,
                'source_kind' => 'manual',
                'charge_kind' => $validated['period'] === Period::OneTime ? ChargeKind::OneTime : ChargeKind::RecurringFixed,
                'period' => $validated['period'],
                'amount_minor' => $validated['amount']?->amountMinor,
                'currency' => $validated['amount']?->currency,
                'amount_state' => $validated['amount'] === null ? 'unknown' : 'known',
                'evidence_state' => 'manual',
                'valid_from' => $validated['valid_from'],
                'valid_to' => $validated['valid_to'],
                'observed_at' => now(),
                'notes' => $validated['notes'],
            ]);

            if ($validated['renews_at'] !== null) {
                Renewal::create([
                    'cost_item_id' => $costItem->id,
                    'renews_at' => $validated['renews_at'],
                    'auto_renew' => $validated['auto_renew'],
                ]);
            }

            return $costItem;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveService(array $validated): Service
    {
        if ($validated['covers_service_id'] !== null) {
            return Service::query()->whereKey($validated['covers_service_id'])->firstOrFail();
        }

        $service = new Service;
        $service->vendor = $validated['vendor'];
        $service->name = $validated['name'];
        $service->category = $validated['category'];
        $service->url = $validated['url'];
        $service->lifecycle_state = ServiceLifecycle::Active;
        $service->first_seen_at = $validated['valid_from'];
        $service->last_seen_at = $validated['valid_from'];
        $service->save();

        return $service;
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
            'covers_service_id' => ['nullable', Rule::exists(Service::class, 'id')],
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
            'covers_service_id' => $validated['covers_service_id'] ?? null,
        ];
    }
}
