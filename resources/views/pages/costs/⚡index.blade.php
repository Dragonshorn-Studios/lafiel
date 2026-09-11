<?php

use App\Domain\Costs\Actions\CreateManualCost;
use App\Domain\Costs\Actions\EndManualCost;
use App\Domain\Costs\Actions\UpdateManualCost;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\CostItem;
use Flux\Flux;
use App\Domain\Inventory\Models\Service;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Services')] class extends Component {
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

    /**
     * The Services table: every service with a roll-up of its open
     * winning charges. The read model owns all equivalent math.
     */
    #[Computed]
    public function rows(): array
    {
        return app(\App\Domain\Inventory\ServiceLedger::class)->rows(now());
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
            $item = CostItem::find($this->editingCostItemId);

            if ($item === null) {
                Flux::toast(variant: 'warning', text: __('This cost has already been ended or changed elsewhere. Reload and try again.'));
                $this->closePanel();

                return;
            }

            $input['price_changed'] = $this->priceChanged();

            app(UpdateManualCost::class)->update($item, $input);
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
            Flux::toast(variant: 'warning', text: __('This cost has already been ended or changed elsewhere. Reload and try again.'));

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
        $item = CostItem::find($costItemId);

        if ($item === null) {
            Flux::toast(variant: 'warning', text: __('This cost has already been ended or changed elsewhere. Reload and try again.'));

            return;
        }

        app(EndManualCost::class)->end($item);

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

    /**
     * The renewal date auto-renew assumes when none is set: one period
     * after the charge's start. Null when the period cannot renew.
     */
    public function assumedRenewalDate(): ?string
    {
        if ($this->renewsAt !== '' || ! $this->autoRenew) {
            return null;
        }

        $from = new CarbonImmutable($this->validFrom === '' ? now()->toDateString() : $this->validFrom);

        return Period::from($this->period)->advance($from)?->format('Y-m-d');
    }

    /**
     * Present the row's rounded-once monthly equivalent.
     */
    public function major(\App\Domain\Inventory\ServiceLedgerRow $row): string
    {
        return \App\Domain\Support\ValueObjects\Money::ofMinor((int) $row->monthlyMinor, (string) $row->monthlyCurrency)->majorAmount();
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
        <flux:heading size="h1">{{ __('Services') }}</flux:heading>

        <flux:button variant="primary" icon="plus" wire:click="add" data-test="add-cost-button">
            {{ __('Add cost') }}
        </flux:button>
    </div>

    @if ($this->rows === [])
        <x-imperial.empty-state :hint="__('Manual charges and provider services will appear here once added.')">
            <flux:button variant="primary" wire:click="add" class="mt-2">
                {{ __('Add cost') }}
            </flux:button>
        </x-imperial.empty-state>
    @else
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Provider') }}</flux:table.column>
            <flux:table.column>{{ __('Service') }}</flux:table.column>
            <flux:table.column>{{ __('Category') }}</flux:table.column>
            <flux:table.column>{{ __('Billing') }}</flux:table.column>
            <flux:table.column>{{ __('Source amount') }}</flux:table.column>
            <flux:table.column>{{ __('Monthly equivalent') }}</flux:table.column>
            <flux:table.column>{{ __('Renewal') }}</flux:table.column>
            <flux:table.column>{{ __('Freshness') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->rows as $row)
                <flux:table.row :key="$row->service->id">
                    <flux:table.cell>{{ $row->provider }}</flux:table.cell>

                    <flux:table.cell>
                        <flux:link :href="route('services.show', $row->service)" wire:navigate class="font-medium">
                            {{ $row->service->name }}
                        </flux:link>
                        <span class="flex flex-wrap gap-1 pt-1">
                            @if ($row->package)
                                <flux:badge size="sm" variant="info">{{ __('package') }}</flux:badge>
                            @endif
                            @if ($row->unknownCount > 0)
                                <flux:badge size="sm">{{ __(':n unknown', ['n' => $row->unknownCount]) }}</flux:badge>
                            @endif
                            @if ($row->staleCount > 0)
                                <flux:badge size="sm" variant="warning">{{ __('stale') }}</flux:badge>
                            @endif
                        </span>
                    </flux:table.cell>

                    <flux:table.cell>{{ $row->service->category }}</flux:table.cell>

                    <flux:table.cell>
                        {{ $row->billing ?? '—' }}
                        @if ($row->chargeCount > 1)
                            <span class="block text-xs text-ink-muted">{{ __(':n charges', ['n' => $row->chargeCount]) }}</span>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="font-mono tabular-nums">
                        @if ($row->sourceAmount === null)
                            {{ __('unknown') }}
                        @else
                            {{ $row->sourceAmount->majorAmount() }} {{ $row->sourceAmount->currency }}
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="font-mono tabular-nums">
                        @if ($row->monthlyMinor === null)
                            {{ __('unknown') }}
                        @else
                            {{ $this->major($row) }} {{ $row->monthlyCurrency }}
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="font-mono tabular-nums">
                        @if ($row->renewsAt !== null)
                            {{ $row->renewsAt->format('Y-m-d') }}
                            {{ $row->autoRenew ? __('(auto)') : '' }}
                        @else
                            —
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($row->freshness() === 'stale')
                            <flux:badge size="sm" variant="warning">{{ __('Stale') }}</flux:badge>
                        @elseif ($row->freshness() === 'manual')
                            {{ __('Manual') }}
                        @else
                            {{ __('Synced') }}
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($row->manualChargeId !== null)
                            <flux:button size="xs" wire:click="edit({{ $row->manualChargeId }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="xs" variant="danger" wire:click="end({{ $row->manualChargeId }})" wire:confirm="{{ __('End this cost?') }}">
                                {{ __('End') }}
                            </flux:button>
                        @else
                            <flux:link :href="route('services.show', $row->service)" wire:navigate size="sm">{{ __('Details') }}</flux:link>
                        @endif
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

            <div>
                <flux:checkbox wire:model="unknownAmount" :label="__('Amount unknown')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="validFrom" :label="__('Start')" type="date" required />
                <flux:input wire:model="validTo" :label="__('End')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="renewsAt" :label="__('Next renewal')" type="date" />
                <div class="flex items-center">
                    <flux:checkbox wire:model="autoRenew" :label="__('Auto-renew')" />
                </div>
            </div>

            @if ($renewsAt === '' && $autoRenew)
                @if ($assumed = $this->assumedRenewalDate())
                    <p class="text-xs text-ink-muted">
                        {{ __('No date set — the next renewal is assumed to be :date, one period after the start.', ['date' => $assumed]) }}
                    </p>
                @else
                    <p class="text-xs text-ink-muted">{{ __('This period cannot renew automatically — set a renewal date instead.') }}</p>
                @endif
            @endif

            <flux:input wire:model="url" :label="__('URL')" type="url" />
            <flux:textarea wire:model="notes" :label="__('Notes')" />

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" type="submit" data-test="save-cost">{{ __('Save') }}</flux:button>
                <flux:button type="button" wire:click="closePanel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
