<?php

use App\Domain\Costs\Models\CostItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Cost history')] class extends Component {
    #[Computed]
    public function history(): \Illuminate\Support\Collection
    {
        return CostItem::query()
            ->where('source_kind', 'manual')
            ->whereNotNull('valid_to')
            ->with(['services', 'renewal'])
            ->orderByDesc('valid_to')
            ->orderByDesc('valid_from')
            ->get();
    }
}; ?>
<section class="w-full space-y-6">
    <flux:heading size="h1">{{ __('Cost history') }}</flux:heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column>{{ __('Amount') }}</flux:table.column>
            <flux:table.column>{{ __('Period') }}</flux:table.column>
            <flux:table.column>{{ __('Valid from') }}</flux:table.column>
            <flux:table.column>{{ __('Valid to') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->history as $item)
                @php($service = $item->services->first())
                <flux:table.row :key="$item->id">
                    <flux:table.cell>{{ $service?->name }}<span class="block text-xs text-zinc-500">{{ $service?->vendor }}</span></flux:table.cell>
                    <flux:table.cell>
                        @if ($item->amount_state->value === 'unknown')
                            {{ __('unknown') }}
                        @else
                            {{ \App\Domain\Support\ValueObjects\Money::ofMinor($item->amount_minor, (string) $item->currency)->majorAmount() }} {{ $item->currency }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $item->period->value }}</flux:table.cell>
                    <flux:table.cell>{{ $item->valid_from->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>{{ $item->valid_to?->format('Y-m-d') }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
