<?php

use App\Domain\Costs\Models\CostItem;
use App\Domain\Support\ValueObjects\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Service')] class extends Component {
    public \App\Domain\Inventory\Models\Service $service;

    public function mount(\App\Domain\Inventory\Models\Service $service): void
    {
        $this->service = $service;
    }

    /**
     * Every charge version that ever covered this service, open or
     * ended — the origin section of the detail view.
     */
    #[Computed]
    public function charges(): \Illuminate\Support\Collection
    {
        return $this->service->costItems()
            ->with(['renewal', 'services.providerAccount'])
            ->orderByDesc('valid_from')
            ->orderBy('id')
            ->get();
    }

    /**
     * Charges that cover more than this service: package membership.
     *
     * @return \Illuminate\Support\Collection<int, array{charge: CostItem, others: \Illuminate\Support\Collection}>
     */
    #[Computed]
    public function packages(): \Illuminate\Support\Collection
    {
        return $this->charges
            ->filter(fn (CostItem $charge): bool => $charge->services->count() > 1)
            ->map(fn (CostItem $charge): array => [
                'charge' => $charge,
                'others' => $charge->services->reject(fn ($covered): bool => $covered->is($this->service)),
            ])
            ->values();
    }

    public function major(?Money $money): string
    {
        return $money?->majorAmount() ?? __('unknown');
    }
}; ?>

<section class="w-full space-y-6">
    <div>
        <flux:link :href="route('costs.index')" wire:navigate class="text-sm">{{ __('← Services') }}</flux:link>
        <flux:heading size="h1" class="mt-2">{{ $this->service->name }}</flux:heading>
        <flux:subheading>
            {{ $this->service->providerAccount?->display_name ?? __('Manual') }} · {{ $this->service->category }}
        </flux:subheading>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Identity --}}
        <flux:card>
            <flux:heading size="lg">{{ __('Service') }}</flux:heading>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-secondary">{{ __('Provider') }}</dt>
                    <dd>{{ $this->service->providerAccount?->display_name ?? __('Manual') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-secondary">{{ __('Category') }}</dt>
                    <dd>{{ $this->service->category }}</dd>
                </div>
                @if (filled($this->service->vendor))
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-secondary">{{ __('Vendor') }}</dt>
                        <dd>{{ $this->service->vendor }}</dd>
                    </div>
                @endif
                @if (filled($this->service->url))
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-secondary">{{ __('URL') }}</dt>
                        <dd class="truncate"><a href="{{ $this->service->url }}" target="_blank" rel="noopener noreferrer" class="text-info underline">{{ $this->service->url }}</a></dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-secondary">{{ __('Lifecycle') }}</dt>
                    <dd>{{ $this->service->lifecycle_state->value }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-secondary">{{ __('First seen') }}</dt>
                    <dd class="font-mono tabular-nums">{{ $this->service->first_seen_at?->format('Y-m-d') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-secondary">{{ __('Last seen') }}</dt>
                    <dd class="font-mono tabular-nums">{{ $this->service->last_seen_at?->format('Y-m-d') ?? '—' }}</dd>
                </div>
            </dl>
        </flux:card>

        {{-- Package membership --}}
        <flux:card>
            <flux:heading size="lg">{{ __('Packages') }}</flux:heading>

            @if ($this->packages->isEmpty())
                <p class="mt-4 text-sm text-ink-secondary">{{ __('Every charge on this service belongs to it alone.') }}</p>
            @else
                <div class="mt-4 space-y-4">
                    @foreach ($this->packages as $package)
                        <div class="rounded-card border border-line bg-surface-subtle/60 p-4 text-sm">
                            <p class="font-medium text-ink">
                                {{ __('Shared charge') }} · {{ $package['charge']->period->value }}
                                @if ($package['charge']->amount_state->value === 'known')
                                    · <span class="font-mono tabular-nums">{{ $this->major($package['charge']->money()) }} {{ $package['charge']->currency }}</span>
                                @endif
                            </p>
                            <p class="mt-1 text-ink-secondary">
                                {{ __('Covers :n services:', ['n' => $package['charge']->services->count()]) }}
                                {{ $package['others']->pluck('name')->join(', ') }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
    </div>

    {{-- Charges with origin --}}
    <flux:card>
        <flux:heading size="lg">{{ __('Charges') }}</flux:heading>

        @if ($this->charges->isEmpty())
            <p class="mt-4 text-sm text-ink-secondary">{{ __('No charges cover this service yet.') }}</p>
        @else
            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Origin') }}</flux:table.column>
                    <flux:table.column>{{ __('Evidence') }}</flux:table.column>
                    <flux:table.column>{{ __('Amount') }}</flux:table.column>
                    <flux:table.column>{{ __('Valid from') }}</flux:table.column>
                    <flux:table.column>{{ __('Valid to') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->charges as $charge)
                        <flux:table.row :key="$charge->id">
                            <flux:table.cell>
                                @if ($charge->valid_to === null)
                                    <flux:badge size="sm" variant="success">{{ __('Open') }}</flux:badge>
                                @else
                                    <flux:badge size="sm">{{ __('Ended') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $charge->source_kind->value }}
                                @if (filled($charge->source_ref))
                                    <span class="block font-mono text-xs text-ink-muted">{{ $charge->source_ref }}</span>
                                @endif
                                @if ($charge->observed_at !== null)
                                    <span class="block text-xs text-ink-muted">{{ __('Observed :at', ['at' => $charge->observed_at->format('Y-m-d H:i')]) }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :variant="$charge->evidence_state->value === 'actual' ? 'success' : 'neutral'">
                                    {{ $charge->evidence_state->value }}
                                </flux:badge>
                                @if ($charge->allocation_state->value === 'shared_unallocated')
                                    <flux:badge size="sm" variant="warning">{{ __('shared unallocated') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono tabular-nums">
                                {{ $this->major($charge->money()) }}
                                @if ($charge->currency !== null)
                                    {{ $charge->currency }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono tabular-nums">{{ $charge->valid_from->format('Y-m-d') }}</flux:table.cell>
                            <flux:table.cell class="font-mono tabular-nums">{{ $charge->valid_to?->format('Y-m-d') ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        @php($renewal = $this->charges->first()?->renewal)
        @if ($renewal !== null)
            <p class="mt-4 text-sm text-ink-secondary">
                {{ __('Next renewal :date (:auto).', ['date' => $renewal->renews_at->format('Y-m-d'), 'auto' => $renewal->auto_renew ? __('automatic') : __('manual')]) }}
            </p>
        @endif
    </flux:card>
</section>
