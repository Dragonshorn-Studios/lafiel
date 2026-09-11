<?php

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Actions\EndManualCost;
use App\Domain\Costs\Actions\UpdateManualCost;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\CostItem;
use App\Domain\Inventory\Models\Service;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Costs')] class extends Component {
    public string $vendor = '';

    public string $name = '';

    public string $category = '';

    public bool $unknownAmount = false;

    public string $amount = '';

    public string $currency = 'PLN';

    public string $period = 'monthly';

    public string $validFrom = '';

    public string $validTo = '';

    public string $renewsAt = '';

    public bool $autoRenew = false;

    public string $url = '';

    public string $notes = '';

    public ?int $coversServiceId = null;

    public ?int $editingCostItemId = null;

    public bool $panelOpen = false;

    /**
     * The initial price fields, to detect a price change on save.
     *
     * @var array{amount: string, currency: string, period: string}
     */
    public array $loadedPrice = ['amount' => '', 'currency' => 'PLN', 'period' => 'monthly'];

    public function mount(): void
    {
        $this->validFrom = now()->format('Y-m-d');
    }

    /**
     * Open the slide-over with a clean form for a new charge.
     */
    public function add(): void
    {
        $this->resetForm();
        $this->panelOpen = true;
    }

    public function closePanel(): void
    {
        $this->resetForm();
        $this->panelOpen = false;
    }

    #[Computed]
    public function costs(): \Illuminate\Support\Collection
    {
        return CostItem::query()
            ->where('source_kind', 'manual')
            ->whereNull('valid_to')
            ->with(['services', 'renewal'])
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function overlayCandidates(): \Illuminate\Support\Collection
    {
        return Service::query()
            ->whereNotNull('provider_account_id')
            ->orderBy('name')
            ->get(['id', 'name', 'provider_type']);
    }

    public function save(): void
    {
        $input = [
            'vendor' => $this->vendor,
            'name' => $this->name,
            'category' => $this->category,
            'unknown_amount' => $this->unknownAmount,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'period' => $this->period,
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo ?: null,
            'renews_at' => $this->renewsAt ?: null,
            'auto_renew' => $this->autoRenew,
            'url' => $this->url,
            'notes' => $this->notes,
            'covers_service_id' => $this->coversServiceId,
        ];

        if ($this->editingCostItemId !== null) {
            $input['price_changed'] = $this->priceChanged();

            app(UpdateManualCost::class)->update(CostItem::findOrFail($this->editingCostItemId), $input);
        } else {
            app(CreateManualCost::class)->create($input);
        }

        $this->resetForm();
        $this->panelOpen = false;
    }

    public function edit(int $costItemId): void
    {
        $open = CostItem::query()
            ->whereKey($costItemId)
            ->whereNull('valid_to')
            ->with(['services', 'renewal'])
            ->first();

        if ($open === null) {
            return;
        }

        $service = $open->services->first();

        $this->editingCostItemId = $open->id;
        $this->vendor = (string) $service?->vendor;
        $this->name = (string) $service?->name;
        $this->category = (string) $service?->category;
        $this->unknownAmount = $open->amount_state->value === 'unknown';
        $this->amount = $open->money()?->majorAmount() ?? '';
        $this->currency = $open->currency ?? 'PLN';
        $this->period = $open->period->value;
        $this->validFrom = $open->valid_from->format('Y-m-d');
        $this->validTo = $open->valid_to?->format('Y-m-d') ?? '';
        $this->renewsAt = $open->renewal?->renews_at->format('Y-m-d') ?? '';
        $this->autoRenew = $open->renewal?->auto_renew ?? false;
        $this->url = (string) $service?->url;
        $this->notes = (string) $open->notes;
        $this->loadedPrice = ['amount' => $this->amount, 'currency' => $this->currency, 'period' => $this->period];
        $this->panelOpen = true;
    }

    public function end(int $costItemId): void
    {
        app(EndManualCost::class)->end(CostItem::findOrFail($costItemId));

        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->reset('vendor', 'name', 'category', 'unknownAmount', 'amount', 'currency', 'period', 'validTo', 'renewsAt', 'autoRenew', 'url', 'notes', 'coversServiceId', 'editingCostItemId');
        $this->validFrom = now()->format('Y-m-d');
        $this->currency = 'PLN';
        $this->period = 'monthly';
        $this->loadedPrice = ['amount' => '', 'currency' => 'PLN', 'period' => 'monthly'];
    }

    private function priceChanged(): bool
    {
        if ($this->unknownAmount) {
            return $this->loadedPrice['amount'] !== '' || $this->period !== $this->loadedPrice['period'];
        }

        return $this->amount !== $this->loadedPrice['amount']
            || $this->currency !== $this->loadedPrice['currency']
            || $this->period !== $this->loadedPrice['period'];
    }
}; ?>
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="h1">{{ __('Costs') }}</flux:heading>

        <flux:button variant="primary" icon="plus" wire:click="add" data-test="add-cost-button">
            {{ __('Add cost') }}
        </flux:button>
    </div>

    @if ($this->costs->isEmpty())
        <x-imperial.empty-state :hint="__('Manual charges and provider services will appear here once added.')">
            <flux:button variant="primary" wire:click="add" class="mt-2">
                {{ __('Add cost') }}
            </flux:button>
        </x-imperial.empty-state>
    @else
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column>{{ __('Vendor') }}</flux:table.column>
            <flux:table.column>{{ __('Amount') }}</flux:table.column>
            <flux:table.column>{{ __('Period') }}</flux:table.column>
            <flux:table.column>{{ __('Renews') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->costs as $item)
                @php($service = $item->services->first())
                <flux:table.row :key="$item->id">
                    <flux:table.cell>{{ $service?->name }}<span class="block text-xs text-zinc-500">{{ $service?->category }}</span></flux:table.cell>
                    <flux:table.cell>{{ $service?->vendor }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($item->amount_state->value === 'unknown')
                            {{ __('unknown') }}
                        @else
                            {{ $item->money()?->majorAmount() }} {{ $item->currency }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $item->period->value }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($item->renewal)
                            {{ $item->renewal->renews_at->format('Y-m-d') }}
                            {{ $item->renewal->auto_renew ? __('(auto)') : '' }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="xs" wire:click="edit({{ $item->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button size="xs" variant="danger" wire:click="end({{ $item->id }})" wire:confirm="{{ __('End this cost?') }}">
                            {{ __('End') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    @endif

    {{-- The add/edit form lives in a right-side pop-out panel. --}}
    <flux:modal name="cost-form" variant="flyout" wire:model="panelOpen" class="w-full max-w-lg">
        <flux:heading size="lg" class="mb-6">
            {{ $editingCostItemId !== null ? __('Edit cost') : __('Add manual cost') }}
        </flux:heading>

        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="vendor" :label="__('Vendor')" />
                <flux:input wire:model="name" :label="__('Service name')" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="category" :label="__('Category')" required placeholder="ai, domain, license, saas…" />
                <flux:select wire:model="coversServiceId" :label="__('Cover an existing service')" :disabled="$editingCostItemId !== null">
                    <flux:select.option :value="null">{{ __('— new service —') }}</flux:select.option>
                    @foreach ($this->overlayCandidates as $candidate)
                        <flux:select.option :value="$candidate->id">{{ $candidate->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="amount" :label="__('Amount')" type="number" step="0.01" :disabled="$unknownAmount" />
                <flux:input wire:model="currency" :label="__('Currency')" />
                <flux:select wire:model="period" :label="__('Period')">
                    @foreach (Period::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->value }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:checkbox wire:model="unknownAmount" :label="__('Amount unknown')" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="validFrom" :label="__('Start')" type="date" required />
                <flux:input wire:model="validTo" :label="__('End')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="renewsAt" :label="__('Next renewal')" type="date" />
                <flux:checkbox wire:model="autoRenew" :label="__('Auto-renew')" />
            </div>

            <flux:input wire:model="url" :label="__('URL')" type="url" />
            <flux:textarea wire:model="notes" :label="__('Notes')" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" type="submit" data-test="save-cost">{{ __('Save') }}</flux:button>
                <flux:button type="button" wire:click="closePanel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
