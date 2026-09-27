<?php

use App\Domain\Costs\Actions\ImportSubscriptionPresets;
use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\SubscriptionPreset;
use App\Domain\Support\ValueObjects\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plans')] class extends Component {
    public bool $panelOpen = false;

    public ?int $editingPresetId = null;

    public string $key = '';

    public string $label = '';

    public string $vendor = '';

    public string $name = '';

    public string $category = 'saas';

    public string $amount = '';

    public string $currency = 'USD';

    public string $period = 'monthly';

    public bool $autoRenew = true;

    public string $url = '';

    /**
     * The whole library, archived plans included — the page shows
     * where each plan stands and restores them from here.
     */
    #[Computed]
    public function presets(): Collection
    {
        return SubscriptionPreset::query()->orderBy('vendor')->orderBy('name')->get();
    }

    /**
     * Open the slide-over with a clean form for a new plan.
     */
    public function add(): void
    {
        $this->resetForm();
        $this->panelOpen = true;
    }

    public function edit(int $presetId): void
    {
        $preset = $this->preset($presetId);

        $this->editingPresetId = $preset->id;
        $this->key = $preset->key;
        $this->label = $preset->label;
        $this->vendor = $preset->vendor;
        $this->name = $preset->name;
        $this->category = $preset->category;
        $this->amount = $preset->money()->majorAmount();
        $this->currency = $preset->currency;
        $this->period = $preset->period->value;
        $this->autoRenew = $preset->auto_renew;
        $this->url = (string) $preset->url;
        $this->panelOpen = true;
    }

    public function save(): void
    {
        $this->key = trim($this->key);
        $this->label = trim($this->label);
        $this->vendor = trim($this->vendor);
        $this->name = trim($this->name);

        if ($this->key === '') {
            $this->key = $this->generateKey();
        }

        $validated = $this->validate($this->formRules(), attributes: $this->formAttributes());

        try {
            $money = Money::ofString($validated['amount'], $validated['currency']);
        } catch (\InvalidArgumentException) {
            $this->addError('amount', __('This amount is outside the supported range.'));

            return;
        }

        $attributes = [
            'key' => $validated['key'],
            'label' => $validated['label'] !== '' ? $validated['label'] : $this->generateLabel($validated),
            'vendor' => $validated['vendor'],
            'name' => $validated['name'],
            'category' => $validated['category'],
            'amount_minor' => $money->amountMinor,
            'currency' => $money->currency,
            'period' => $validated['period'],
            'auto_renew' => $validated['autoRenew'],
            'url' => $validated['url'] !== '' ? $validated['url'] : null,
        ];

        if ($this->editingPresetId !== null) {
            $this->preset($this->editingPresetId)->update($attributes);
        } else {
            SubscriptionPreset::create($attributes);
        }

        Flux::toast(variant: 'success', text: __('Plan saved.'));

        $this->closePanel();
    }

    public function archive(int $presetId): void
    {
        $preset = $this->preset($presetId);

        if ($preset->archived_at === null) {
            $preset->archived_at = now();
            $preset->save();
        }

        Flux::toast(variant: 'success', text: __('Plan archived. Existing costs are untouched.'));
    }

    public function restore(int $presetId): void
    {
        $preset = $this->preset($presetId);

        if ($preset->archived_at !== null) {
            $preset->archived_at = null;
            $preset->save();
        }

        Flux::toast(variant: 'success', text: __('Plan restored.'));
    }

    /**
     * Start a recurring cost from a plan: hand off to the costs page
     * with the add-cost flyout prefilled from the preset.
     */
    public function addAsCost(int $presetId): void
    {
        $preset = SubscriptionPreset::query()->active()->find($presetId);

        if ($preset === null) {
            Flux::toast(variant: 'warning', text: __('This plan is archived. Restore it to add costs from it.'));

            return;
        }

        $this->redirect(route('costs.add', ['preset' => $preset->id]), navigate: true);
    }

    /**
     * Pull the remote catalog (AI_PRESETS_URL by default) as an
     * explicit, user-triggered action; entries are upserted by key.
     */
    public function importFromUrl(): void
    {
        try {
            $result = app(ImportSubscriptionPresets::class)->import();
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        Flux::toast(
            variant: 'success',
            text: __('Imported :imported plans (:updated updated, :skipped skipped).', ['imported' => $result['imported'], 'updated' => $result['updated'], 'skipped' => $result['skipped']]),
        );
    }

    public function closePanel(): void
    {
        $this->resetForm();
        $this->panelOpen = false;
    }

    /**
     * Present the plan's amount as a plain major-unit string.
     */
    public function major(SubscriptionPreset $preset): string
    {
        return $preset->money()->majorAmount();
    }

    private function preset(int $presetId): SubscriptionPreset
    {
        return SubscriptionPreset::query()->findOrFail($presetId);
    }

    private function resetForm(): void
    {
        $this->reset('editingPresetId', 'key', 'label', 'vendor', 'name', 'category', 'amount', 'currency', 'period', 'autoRenew', 'url');
        $this->category = 'saas';
        $this->currency = 'USD';
        $this->period = 'monthly';
        $this->autoRenew = true;
    }

    /**
     * A blank key derives one from vendor and name, e.g.
     * `openai:chatgpt-plus`. Collisions surface as a validation error.
     */
    private function generateKey(): string
    {
        $base = Str::slug($this->vendor).':'.Str::slug($this->name);

        return trim($base, ':');
    }

    /**
     * A blank display label mirrors the built-in catalog's style,
     * e.g. `OpenAI — ChatGPT Plus ($20.00/mo)`; non-USD plans carry
     * the ISO code instead of the implicit dollar sign.
     *
     * @param  array<string, mixed>  $validated
     */
    private function generateLabel(array $validated): string
    {
        $per = match ((string) $validated['period']) {
            'monthly' => 'mo',
            'annual' => 'yr',
            default => (string) $validated['period'],
        };

        $marker = strtoupper((string) $validated['currency']) === 'USD'
            ? '$'
            : '';

        $currencySuffix = $marker === '' ? ' '.strtoupper((string) $validated['currency']) : '';

        return sprintf('%s — %s (%s%s%s/%s)', $validated['vendor'], $validated['name'], $marker, $validated['amount'], $currencySuffix, $per);
    }

    /**
     * @return array<string, mixed>
     */
    private function formRules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('subscription_presets', 'key')->ignore($this->editingPresetId),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'vendor' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            // No leading minus: a plan is a catalog price, never a
            // credit — Money::ofString's own pattern accepts one, the
            // column CHECK rejects it, so this catches it as a field
            // error instead. Keep the rest a subset of Money's pattern
            // so a format typo never becomes the vaguer range error.
            'amount' => ['required', 'string', 'regex:/^(\d+)(?:\.(\d{1,2}))?$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'period' => ['required', Rule::enum(Period::class)],
            'autoRenew' => ['boolean'],
            'url' => ['nullable', 'string', 'max:2048', 'starts_with:http://,https://'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function formAttributes(): array
    {
        return [
            'key' => __('key'),
            'label' => __('label'),
            'vendor' => __('vendor'),
            'name' => __('name'),
            'category' => __('category'),
            'amount' => __('amount'),
            'currency' => __('currency'),
            'period' => __('period'),
            'autoRenew' => __('auto-renew'),
            'url' => __('URL'),
        ];
    }
}; ?>
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="h1">{{ __('Plans') }}</flux:heading>
            <p class="text-sm text-ink-muted">{{ __('A library of subscription plans to start recurring costs from.') }}</p>
        </div>

        <div class="flex items-center gap-4">
            <flux:button icon="arrow-down-tray" wire:click="importFromUrl" wire:confirm="{{ __('Importing overwrites existing plans that carry the same key. Continue?') }}" data-test="import-presets-button">
                {{ __('Import from catalog') }}
            </flux:button>

            <flux:button variant="primary" icon="plus" wire:click="add" data-test="add-preset-button">
                {{ __('Add plan') }}
            </flux:button>
        </div>
    </div>

    @if ($this->presets->isEmpty())
        <x-imperial.empty-state :hint="__('Plans you add or import appear here, ready to be added as recurring costs.')">
            <flux:button variant="primary" wire:click="add" class="mt-2">
                {{ __('Add plan') }}
            </flux:button>
        </x-imperial.empty-state>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Vendor') }}</flux:table.column>
                <flux:table.column>{{ __('Plan') }}</flux:table.column>
                <flux:table.column>{{ __('Amount') }}</flux:table.column>
                <flux:table.column>{{ __('Period') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->presets as $preset)
                    <flux:table.row :key="$preset->id">
                        <flux:table.cell>{{ $preset->vendor }}</flux:table.cell>

                        <flux:table.cell>
                            <span class="font-medium">{{ $preset->name }}</span>
                            <span class="block text-xs text-ink-muted">{{ $preset->label }}</span>
                        </flux:table.cell>

                        <flux:table.cell class="font-mono tabular-nums">
                            {{ $this->major($preset) }} {{ $preset->currency }}
                        </flux:table.cell>

                        <flux:table.cell>{{ $preset->period }}</flux:table.cell>

                        <flux:table.cell>
                            @if ($preset->archived_at === null)
                                <flux:badge size="sm" variant="success">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge size="sm">{{ __('Archived') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:button size="xs" wire:click="addAsCost({{ $preset->id }})" data-test="add-as-cost-{{ $preset->id }}">
                                {{ __('Add as recurring cost') }}
                            </flux:button>
                            <flux:button size="xs" wire:click="edit({{ $preset->id }})" data-test="edit-preset-{{ $preset->id }}">{{ __('Edit') }}</flux:button>
                            @if ($preset->archived_at === null)
                                <flux:button size="xs" variant="danger" wire:click="archive({{ $preset->id }})" wire:confirm="{{ __('Archive this plan? Existing costs are untouched.') }}" data-test="archive-preset-{{ $preset->id }}">
                                    {{ __('Archive') }}
                                </flux:button>
                            @else
                                <flux:button size="xs" wire:click="restore({{ $preset->id }})" data-test="restore-preset-{{ $preset->id }}">{{ __('Restore') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- The add/edit form lives in a right-side pop-out panel. --}}
    <flux:modal name="preset-form" variant="flyout" wire:model="panelOpen" class="w-full max-w-lg">
        <flux:heading size="lg" class="mb-6">
            {{ $editingPresetId !== null ? __('Edit plan') : __('Add plan') }}
        </flux:heading>

        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="vendor" :label="__('Vendor')" required />
                <flux:input wire:model="name" :label="__('Plan name')" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="category" :label="__('Category')" required placeholder="ai, compute, domain, saas…" />
                <flux:input wire:model="key" :label="__('Key')" placeholder="auto: vendor:name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="amount" :label="__('Amount')" type="number" step="0.01" required />
                <flux:input wire:model="currency" :label="__('Currency')" required />
                <flux:select wire:model="period" :label="__('Period')">
                    @foreach (Period::cases() as $case)
                        <flux:select.option :value="$case->value">{{ $case->value }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex items-center">
                <flux:checkbox wire:model="autoRenew" :label="__('Auto-renew')" />
            </div>

            <flux:input wire:model="label" :label="__('Display label')" placeholder="auto: vendor — name ($amount/period)" />
            <flux:input wire:model="url" :label="__('URL')" type="url" />

            <p class="text-xs text-ink-muted">
                {{ __('Blank key and label are derived from the vendor, name, and amount. Editing or archiving a plan never changes costs already created from it.') }}
            </p>

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" type="submit" data-test="save-preset">{{ __('Save') }}</flux:button>
                <flux:button type="button" wire:click="closePanel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
