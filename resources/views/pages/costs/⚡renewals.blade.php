<?php

use App\Domain\Costs\Models\Renewal;
use Illuminate\Support\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Renewals')] class extends Component {
    #[Computed]
    public function renewals(): \Illuminate\Support\Collection
    {
        return Renewal::query()
            ->whereHas('costItem', fn ($query) => $query
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', today()))
            ->with(['costItem.services'])
            ->orderBy('renews_at')
            ->get();
    }
}; ?>
<section class="w-full space-y-6">
    <flux:heading size="h1">{{ __('Renewals') }}</flux:heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column>{{ __('Renews at') }}</flux:table.column>
            <flux:table.column>{{ __('Auto-renew') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->renewals as $renewal)
                @php($service = $renewal->costItem->services->first())
                <flux:table.row :key="$renewal->id">
                    <flux:table.cell>{{ $service?->name }}</flux:table.cell>
                    <flux:table.cell>{{ $renewal->renews_at->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>{{ $renewal->auto_renew ? __('yes') : __('no') }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
