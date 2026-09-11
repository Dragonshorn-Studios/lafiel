<?php

namespace App\Domain\Costs\Actions;

use App\Domain\Costs\Models\CostItem;
use App\Domain\History\TakeSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EndManualCost
{
    /**
     * End a manual charge. The end date is the item's last charged day:
     * the item leaves future projections and stays in history. Ending an
     * already-ended item is a safe no-op.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function end(CostItem $costItem, array $input = []): void
    {
        $validated = Validator::make($input, [
            'valid_to' => ['nullable', 'date'],
        ])->validate();

        $ended = DB::transaction(function () use ($costItem, $validated): bool {
            if ($costItem->valid_to !== null) {
                return false;
            }

            $costItem->valid_to = isset($validated['valid_to'])
                ? new CarbonImmutable($validated['valid_to'])->endOfDay()
                : new CarbonImmutable()->endOfDay();
            $costItem->save();

            return true;
        });

        if ($ended) {
            app(TakeSnapshot::class)->capture();
        }
    }
}
