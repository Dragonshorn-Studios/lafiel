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
            ->with(['costItem.services.providerAccount'])
            ->orderBy('renews_at')
            ->get();
    }
}; ?>
<section class="w-full space-y-6">
    <flux:heading size="h1">{{ __('Renewals') }}</flux:heading>

    @if ($this->renewals->isEmpty())
        <x-imperial.empty-state :hint="__('Charges with a renewal date will appear here.')"/>
    @else
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column>{{ __('Provider') }}</flux:table.column>
            <flux:table.column>{{ __('Source amount') }}</flux:table.column>
            <flux:table.column>{{ __('Renews at') }}</flux:table.column>
            <flux:table.column>{{ __('Auto-renew') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->renewals as $renewal)
                @php($service = $renewal->costItem->services->first())
                <flux:table.row :key="$renewal->id">
                    <flux:table.cell>{{ $service?->name ?? __('Unnamed charge') }}</flux:table.cell>
                    <flux:table.cell>{{ $service?->providerAccount?->display_name ?? __('Manual') }}</flux:table.cell>
                    <flux:table.cell class="font-mono tabular-nums">
                        @if ($renewal->costItem->money() !== null)
                            {{ $renewal->costItem->money()->majorAmount() }} {{ $renewal->costItem->currency }}
                        @else
                            {{ __('unknown') }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $renewal->renews_at->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>{{ $renewal->auto_renew ? __('yes') : __('no') }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    @endif
</section>
