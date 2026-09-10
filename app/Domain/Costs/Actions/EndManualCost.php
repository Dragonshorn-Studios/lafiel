<?php

namespace App\Domain\Costs\Actions;

use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EndManualCost
{
    /**
     * End a manual service's open cost item. The item leaves future
     * projections and stays in history.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function end(Service $service, array $input = []): void
    {
        $validated = Validator::make($input, [
            'valid_to' => ['nullable', 'date'],
        ])->validate();

        DB::transaction(function () use ($service, $validated): void {
            $open = CostItem::query()
                ->where('logical_charge_key', sprintf('manual:service:%d', $service->id))
                ->whereNull('valid_to')
                ->first();

            if ($open === null) {
                return;
            }

            $open->valid_to = isset($validated['valid_to'])
                ? new CarbonImmutable($validated['valid_to'])->endOfDay()
                : new CarbonImmutable()->endOfDay();
            $open->save();
        });
    }
}
