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
     * Once a catalog import exists, the built-in transcriptions are a
     * crutch: hidden by default everywhere, revealable here only to
     * be reviewed or deleted.
     */
    public bool $showBuiltins = false;

    public bool $importOpen = false;

    public string $importSource = ImportSubscriptionPresets::SOURCE_LAFIEL;

    public string $importUrl = '';

    /**
     * Whether a live catalog is in use, which hides the built-ins.
     */
    #[Computed]
    public function catalogInUse(): bool
    {
        return SubscriptionPreset::catalogInUse();
    }

    /**
     * The whole library, archived plans included — the page shows
     * where each plan stands and restores them from here. Built-in
     * rows stay hidden while a catalog is in use unless revealed.
     */
    #[Computed]
    public function presets(): Collection
    {
        return SubscriptionPreset::query()
            ->when($this->catalogInUse && ! $this->showBuiltins, fn ($query) => $query->where('source', '!=', SubscriptionPreset::SOURCE_BUILTIN))
            ->orderBy('vendor')
            ->orderBy('name')
            ->get();
    }

    /**
     * Attribution for imported catalog data, one line per distinct
     * catalog host. The license note names the publisher only for
     * catalogs whose license we know (china-ai-arbitrage: CC BY 4.0).
     *
     * @return list<string>
     */
    #[Computed]
    public function attribution(): array
    {
        return SubscriptionPreset::query()
            ->where('source', 'catalog')
            ->whereNotNull('source_url')
            ->distinct()
            ->orderBy('source_url')
            ->pluck('source_url')
            ->map(fn (string $url) => parse_url($url, PHP_URL_HOST))
            ->filter(fn ($host) => is_string($host) && $host !== '')
            ->unique()
            ->values()
            ->map(fn (string $host): string => str_contains($host, 'china-ai-arbitrage')
                ? __('Plan data imported from :host — CC BY 4.0.', ['host' => $host])
                : __('Plan data imported from :host — licensed by its publisher.', ['host' => $host]))
            ->all();
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
            // Updates never touch `source`: editing a built-in keeps it
            // built-in (your edit, its identity), so suppression and
            // deletion semantics stay predictable.
            $this->preset($this->editingPresetId)->update($attributes);
        } else {
            SubscriptionPreset::create($attributes + ['source' => SubscriptionPreset::SOURCE_MANUAL]);
        }

        Flux::toast(variant: 'success', text: __('Plan saved.'));

        $this->closePanel();
    }

    /**
     * Built-in rows are pure prefill — no cost item references them —
     * so they can be deleted outright, but only once a live catalog
     * has replaced them: deleting built-ins with no active catalog
     * would leave the pickers empty.
     */
    public function destroy(int $presetId): void
    {
        $preset = SubscriptionPreset::query()->find($presetId);

        if ($preset === null) {
            Flux::toast(variant: 'warning', text: __('This plan no longer exists. Reload and try again.'));

            return;
        }

        if ($preset->source !== SubscriptionPreset::SOURCE_BUILTIN) {
            Flux::toast(variant: 'warning', text: __('Only built-in plans can be deleted. Archive your own plans instead.'));

            return;
        }

        if (! SubscriptionPreset::catalogInUse()) {
            Flux::toast(variant: 'warning', text: __('Import a catalog first — deleting the built-ins now would leave the library empty.'));

            return;
        }

        $preset->delete();

        Flux::toast(variant: 'success', text: __('Built-in plan deleted.'));
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
        $preset = SubscriptionPreset::query()->visible()->find($presetId);

        if ($preset === null) {
            Flux::toast(variant: 'warning', text: __('This plan is archived or hidden. Restore or reveal it to add costs from it.'));

            return;
        }

        $this->redirect(route('costs.add', ['preset' => $preset->id]), navigate: true);
    }

    /**
     * Open the import flyout with the chosen source's default URL.
     */
    public function openImport(): void
    {
        $this->importUrl = $this->defaultImportUrl($this->importSource);
        $this->importOpen = true;
    }

    /**
     * Switching source swaps in that source's default URL.
     */
    public function updatedImportSource(string $value): void
    {
        $this->importUrl = $this->defaultImportUrl($value);
    }

    /**
     * Pull the remote catalog as an explicit, user-triggered action;
     * entries are upserted by key and tagged as catalog-sourced.
     */
    public function importFromUrl(): void
    {
        try {
            $result = app(ImportSubscriptionPresets::class)->import($this->importUrl ?: null, $this->importSource);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        } catch (\Throwable $exception) {
            // Rollback already preserved the old library; the cause is
            // in the logs, the user gets a clean retry.
            report($exception);

            Flux::toast(variant: 'danger', text: __('The import failed unexpectedly. Please try again.'));

            return;
        }

        Flux::toast(
            variant: 'success',
            text: __('Imported :imported plans (:updated updated, :skipped skipped).', ['imported' => $result['imported'], 'updated' => $result['updated'], 'skipped' => $result['skipped']]),
        );

        $this->importOpen = false;
    }

    private function defaultImportUrl(string $source): string
    {
        return $source === ImportSubscriptionPresets::SOURCE_CHINA_AI_ARBITRAGE
            ? ImportSubscriptionPresets::CHINA_AI_ARBITRAGE_URL
            : (string) config('services.ai_presets_url');
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
            @if ($this->catalogInUse)
                <flux:checkbox wire:model.live="showBuiltins" :label="__('Show built-in plans')" data-test="show-builtins-toggle" />
            @endif

            <flux:button icon="arrow-down-tray" wire:click="openImport" data-test="import-presets-button">
                {{ __('Import from catalog') }}
            </flux:button>

            <flux:button variant="primary" icon="plus" wire:click="add" data-test="add-preset-button">
                {{ __('Add plan') }}
            </flux:button>
        </div>
    </div>

    @if ($this->attribution !== [])
        @foreach ($this->attribution as $line)
            <p class="text-xs text-ink-muted">{{ $line }}</p>
        @endforeach
    @endif

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
                            @if ($preset->source === SubscriptionPreset::SOURCE_BUILTIN)
                                <flux:badge size="sm">{{ __('Built-in') }}</flux:badge>
                            @elseif ($preset->source === SubscriptionPreset::SOURCE_CATALOG)
                                <flux:badge size="sm" variant="info">{{ __('Catalog') }}</flux:badge>
                            @endif
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

                        {{-- Row actions follow the page chrome: the money
                             action is primary navy, Edit is a neutral
                             secondary, and the destructive Archive/Delete
                             stay quiet danger so they never outshine it. --}}
                        <flux:table.cell>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <flux:button size="xs" variant="primary" wire:click="addAsCost({{ $preset->id }})" data-test="add-as-cost-{{ $preset->id }}">
                                    {{ __('Add as recurring cost') }}
                                </flux:button>
                                <flux:button size="xs" wire:click="edit({{ $preset->id }})" data-test="edit-preset-{{ $preset->id }}">{{ __('Edit') }}</flux:button>
                                @if ($preset->archived_at === null)
                                    <button
                                        type="button"
                                        wire:click="archive({{ $preset->id }})"
                                        wire:confirm="{{ __('Archive this plan? Existing costs are untouched.') }}"
                                        class="inline-flex h-6 cursor-pointer items-center rounded-control border border-danger/40 bg-danger/10 px-2 text-xs font-medium text-danger transition-colors hover:bg-danger/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
                                        data-test="archive-preset-{{ $preset->id }}"
                                    >
                                        {{ __('Archive') }}
                                    </button>
                                @else
                                    <flux:button size="xs" wire:click="restore({{ $preset->id }})" data-test="restore-preset-{{ $preset->id }}">{{ __('Restore') }}</flux:button>
                                @endif
                                @if ($preset->source === SubscriptionPreset::SOURCE_BUILTIN && $this->catalogInUse)
                                    <button
                                        type="button"
                                        wire:click="destroy({{ $preset->id }})"
                                        wire:confirm="{{ __('Delete this built-in plan permanently?') }}"
                                        class="inline-flex h-6 cursor-pointer items-center rounded-control border border-danger/40 bg-danger/10 px-2 text-xs font-medium text-danger transition-colors hover:bg-danger/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
                                        data-test="delete-preset-{{ $preset->id }}"
                                    >
                                        {{ __('Delete') }}
                                    </button>
                                @endif
                            </div>
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

    {{-- The import form: source and URL are explicit, never ambient. --}}
    <flux:modal name="import-form" wire:model="importOpen" class="w-full max-w-md">
        <flux:heading size="lg" class="mb-6">{{ __('Import from catalog') }}</flux:heading>

        <form wire:submit="importFromUrl" class="space-y-4">
            <flux:select wire:model.live="importSource" :label="__('Catalog source')" data-test="import-source">
                <flux:select.option :value="\App\Domain\Costs\Actions\ImportSubscriptionPresets::SOURCE_LAFIEL">{{ __('Lafiel catalog (AI_PRESETS_URL)') }}</flux:select.option>
                <flux:select.option :value="\App\Domain\Costs\Actions\ImportSubscriptionPresets::SOURCE_CHINA_AI_ARBITRAGE">{{ __('china-ai-arbitrage (AI plans, CC BY 4.0)') }}</flux:select.option>
            </flux:select>

            <flux:input wire:model="importUrl" :label="__('Catalog URL')" type="url" data-test="import-url" />

            <p class="text-xs text-ink-muted">
                {{ __('Importing upserts plans by key: the catalog wins for keys it carries. As long as an imported plan stays active, the built-in transcriptions stay hidden. External catalog data remains licensed by its publisher.') }}
            </p>

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" type="submit" wire:confirm="{{ __('Importing overwrites existing plans that carry the same key. Continue?') }}" data-test="import-submit">
                    {{ __('Import') }}
                </flux:button>
                <flux:button type="button" wire:click="$set('importOpen', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
