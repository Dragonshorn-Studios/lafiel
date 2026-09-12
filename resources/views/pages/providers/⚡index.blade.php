<?php

use App\Domain\Providers\Actions\ConnectProviderAccount;
use App\Domain\Providers\Actions\DeleteProviderAccount;
use App\Domain\Providers\Actions\SetProviderAccountEnabled;
use App\Domain\Providers\Actions\TestProviderConnection;
use App\Domain\Providers\Actions\UpdateProviderCredentials;
use App\Domain\Providers\Actions\VerifyCredentials;
use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Enums\ConnectionStatus;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Actions\RequestSync;
use Flux\Flux;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Providers')] class extends Component {
    public string $providerKey = 'ovh';
    public string $displayName = '';

    /**
     * Credential fields keyed by the schema's snake_case payload names;
     * the schema decides which fields exist for the selected provider.
     *
     * @var array<string, string>
     */
    public array $credential = [];

    public ?int $editingAccountId = null;

    public bool $panelOpen = false;

    /**
     * Display labels for the known provider capabilities, in rail order.
     *
     * @var array<string, string>
     */
    public array $capabilityLabels = [
        'inventory' => 'Inventory',
        'renewal_quotes' => 'Renewals',
        'subscriptions' => 'Subscriptions',
        'usage' => 'Usage',
        'invoices' => 'Invoices',
    ];

    /**
     * Outcome of the last connection test per account, kept until the
     * next navigation: status plus the sanitized provider message.
     *
     * @var array<int, array{status: string, message: string}>
     */
    public array $connectionChecks = [];

    #[Computed]
    public function schemas(): CredentialSchemas
    {
        return app(CredentialSchemas::class);
    }

    /**
     * The credential schema behind the panel's selected provider.
     */
    #[Computed]
    public function activeSchema(): string
    {
        return $this->schemas->for($this->providerKey);
    }

    public function updatedProviderKey(): void
    {
        $this->resetCredentialFields();
        $this->resetValidation();
    }

    public function add(): void
    {
        $this->resetForm();
        $this->panelOpen = true;
    }

    public function edit(int $accountId): void
    {
        $account = $this->account($accountId);
        $credential = $account->credentials()->latest('id')->first();

        $this->editingAccountId = $account->id;
        $this->providerKey = $account->provider_key;
        $this->displayName = $account->display_name;
        $this->resetCredentialFields();

        // Non-secret values are shown back; secrets are entered fresh.
        if ($credential !== null) {
            try {
                $payload = $credential->payload;
            } catch (DecryptException) {
                $payload = [];
            }

            foreach ($this->activeSchema::fields() as $field) {
                if ($field->type !== 'password' && isset($payload[$field->name])) {
                    $this->credential[$field->name] = (string) $payload[$field->name];
                }
            }
        }

        $this->resetValidation();
        $this->panelOpen = true;
    }

    public function connect(): void
    {
        $validated = $this->validate($this->formRules());

        app(ConnectProviderAccount::class)->connect($this->providerKey, $this->actionInput($validated));

        $this->closePanel();

        Flux::toast(variant: 'success', text: __('Provider connected. Run a connection test to verify the credentials.'));
    }

    public function update(): void
    {
        $account = $this->account((int) $this->editingAccountId);

        $validated = $this->validate($this->formRules());

        app(UpdateProviderCredentials::class)->update($account, $this->actionInput($validated));

        $this->closePanel();

        Flux::toast(variant: 'success', text: __('Credentials replaced. Run a connection test to verify them.'));
    }

    public function cancelPanel(): void
    {
        $this->closePanel();
    }

    /**
     * Probe the account's stored credentials against its provider. A
     * rejection is a distinct state and never retried; existing
     * history is unchanged.
     */
    public function testConnection(int $accountId): void
    {
        $account = $this->account($accountId);
        $credential = $account->credentials()->latest('id')->first();

        if ($credential === null) {
            Flux::toast(variant: 'danger', text: __('This account has no stored credentials.'));

            return;
        }

        $check = app(TestProviderConnection::class)->check($account, $credential);

        if ($check->status === ConnectionStatus::Connected) {
            app(VerifyCredentials::class)->verify($account, $credential);
        }

        $this->connectionChecks[$accountId] = [
            'status' => $check->status->value,
            'message' => $check->warning ?? __('The provider accepted the credentials.'),
        ];

        Flux::toast(variant: match ($check->status) {
            ConnectionStatus::Connected => 'success',
            ConnectionStatus::Rejected => 'danger',
            ConnectionStatus::Unreachable => 'warning',
        }, text: match ($check->status) {
            ConnectionStatus::Connected => __('Credentials verified.'),
            ConnectionStatus::Rejected => __('The credentials were rejected. Existing history is unchanged.'),
            ConnectionStatus::Unreachable => __('The provider could not be reached. Try again shortly.'),
        });
    }

    public function toggleEnabled(int $accountId): void
    {
        $account = $this->account($accountId);

        app(SetProviderAccountEnabled::class)->set($account, ! $account->enabled);
    }

    /**
     * Queue one sync through the shared entry point, so the UI can
     * never start a parallel run. Only enabled accounts sync.
     */
    public function syncNow(int $accountId): void
    {
        $account = $this->account($accountId);

        if (! $account->enabled) {
            Flux::toast(variant: 'warning', text: __('Sync is paused for this account.'));

            return;
        }

        $run = app(RequestSync::class)->request($account, 'manual');

        if ($run === null) {
            Flux::toast(variant: 'info', text: __('A sync is already running for this account.'));

            return;
        }

        if ($run->status->value === 'failed') {
            Flux::toast(variant: 'danger', text: __('The sync could not be queued. Check the logs.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Sync queued.'));
    }

    public function deleteAccount(int $accountId): void
    {
        app(DeleteProviderAccount::class)->delete($this->account($accountId));

        unset($this->connectionChecks[$accountId]);

        if ($this->editingAccountId === $accountId) {
            $this->closePanel();
        }

        Flux::toast(variant: 'success', text: __('Provider account disconnected. Its synced history was removed.'));
    }

    /**
     * @return Collection<int, ProviderAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return ProviderAccount::query()
            ->orderBy('display_name')
            ->with(['credentials', 'latestSyncRun', 'capabilityStates'])
            ->get();
    }

    /**
     * The stored payload's non-secret summary. A payload that no
     * longer decrypts (rotated APP_KEY) reads as unreadable instead of
     * crashing the page; replacing the credentials fixes it.
     */
    public function credentialSummary(ProviderAccount $account): string
    {
        $credential = $account->credentials->sortByDesc('id')->first();

        if ($credential === null) {
            return '—';
        }

        try {
            return $this->schemas->for($account->provider_key)::summary($credential->payload);
        } catch (DecryptException) {
            return __('unreadable');
        }
    }

    public function providerLabelFor(string $providerKey): string
    {
        try {
            return $this->schemas->for($providerKey)::label();
        } catch (\Throwable) {
            return $providerKey;
        }
    }

    private function account(int $accountId): ProviderAccount
    {
        return ProviderAccount::query()->findOrFail($accountId);
    }

    /**
     * The credential schema speaks snake_case payload keys nested under
     * `credential.`; the page validates exactly what the action receives.
     *
     * @return array<string, list<string>>
     */
    private function formRules(): array
    {
        $rules = ['displayName' => ['required', 'string', 'max:255']];

        foreach ($this->activeSchema::rules() as $key => $cases) {
            $rules['credential.'.$key] = $cases;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function actionInput(array $validated): array
    {
        return [
            'display_name' => $validated['displayName'],
            ...$validated['credential'],
        ];
    }

    private function resetCredentialFields(): void
    {
        $this->credential = [];

        foreach ($this->activeSchema::fields() as $field) {
            $this->credential[$field->name] = '';
        }
    }

    private function resetForm(): void
    {
        $this->editingAccountId = null;
        $this->providerKey = array_key_first($this->schemas->options()) ?? 'ovh';
        $this->displayName = '';
        $this->resetCredentialFields();
        $this->resetValidation();
    }

    private function closePanel(): void
    {
        $this->resetForm();
        $this->panelOpen = false;
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="h1">{{ __('Providers') }}</flux:heading>
            <flux:subheading>{{ __('Provider accounts Lafiel watches, with read-only credentials') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="add" data-test="add-provider-button">
            {{ __('Add provider') }}
        </flux:button>
    </div>

    @if ($this->accounts->isEmpty())
        <x-imperial.empty-state :hint="__('Connect a provider account to import its services and renewal quotes automatically.')">
            <flux:button variant="primary" wire:click="add" class="mt-2">
                {{ __('Add provider') }}
            </flux:button>
        </x-imperial.empty-state>
    @else
        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ($this->accounts as $account)
                @php($check = $connectionChecks[$account->id] ?? null)

                <flux:card data-test="provider-account">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading class="mr-auto">{{ $account->display_name }}</flux:heading>

                        @php($verified = $account->credentials->sortByDesc('id')->first()?->verified_at !== null)

                        @if ($verified)
                            <flux:badge variant="success" size="sm">{{ __('Verified') }}</flux:badge>
                        @else
                            <flux:badge variant="warning" size="sm">{{ __('Not verified') }}</flux:badge>
                        @endif

                        @if (! $account->enabled)
                            <flux:badge size="sm">{{ __('Sync paused') }}</flux:badge>
                        @endif

                        @if ($account->latestSyncRun !== null)
                            <flux:badge :variant="$account->latestSyncRun->status->value === 'succeeded' ? 'success' : 'neutral'" size="sm" data-test="last-run-status">
                                {{ __('Last run: :status', ['status' => $account->latestSyncRun->status->value]) }}
                            </flux:badge>
                        @endif
                    </div>

                    <p class="mt-2 text-sm text-ink-secondary">
                        {{ $this->providerLabelFor($account->provider_key) }} · {{ $this->credentialSummary($account) }}
                        @if ($account->last_success_at !== null)
                            · {{ __('Last successful sync :at', ['at' => $account->last_success_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]) }}
                        @endif
                    </p>

                    {{-- Health per capability (docs/design-system.md, Providers) --}}
                    <div class="mt-3 flex flex-wrap gap-1.5" data-test="capability-health">
                        @foreach ($capabilityLabels as $value => $label)
                            @php($state = $account->capabilityStates->firstWhere('capability_key', $value))
                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs
                                {{ $state === null || ! $state->supported
                                    ? 'border-line text-ink-muted'
                                    : ($state->healthy ? 'border-success/40 bg-success/10 text-success' : 'border-attention/40 bg-attention/10 text-attention') }}"
                                @if ($state?->last_observed_at !== null) title="{{ __('Last observed :at', ['at' => $state->last_observed_at->timezone(config('app.timezone'))->format('Y-m-d H:i')]) }}" @endif
                            >
                                <span class="size-1.5 rounded-full {{ $state === null || ! $state->supported ? 'bg-ink-muted/50' : ($state->healthy ? 'bg-success' : 'bg-attention') }}"></span>
                                {{ $label }}
                            </span>
                        @endforeach
                    </div>

                    @if ($check !== null)
                        <p class="mt-2 text-sm" data-test="connection-check">
                            @if ($check['status'] === ConnectionStatus::Connected->value)
                                <flux:icon.check-circle variant="mini" class="mr-1 inline size-4 text-success" />
                            @elseif ($check['status'] === ConnectionStatus::Rejected->value)
                                <flux:icon.exclamation-triangle variant="mini" class="mr-1 inline size-4 text-danger" />
                            @else
                                <flux:icon.clock variant="mini" class="mr-1 inline size-4 text-attention" />
                            @endif

                            {{ $check['message'] }}
                        </p>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <flux:button size="sm" wire:click="syncNow({{ $account->id }})">
                            {{ __('Sync now') }}
                        </flux:button>

                        <flux:button size="sm" wire:click="testConnection({{ $account->id }})" data-test="test-connection-button">
                            {{ __('Test connection') }}
                        </flux:button>

                        <flux:button size="sm" variant="ghost" wire:click="edit({{ $account->id }})">
                            {{ __('Edit credentials') }}
                        </flux:button>

                        <flux:button size="sm" variant="ghost" wire:click="toggleEnabled({{ $account->id }})">
                            {{ $account->enabled ? __('Pause sync') : __('Resume sync') }}
                        </flux:button>

                        <flux:modal.trigger name="delete-provider-{{ $account->id }}">
                            <flux:button size="sm" variant="danger" data-test="delete-provider-button">
                                {{ __('Disconnect') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </flux:card>

                <flux:modal name="delete-provider-{{ $account->id }}" class="max-w-lg">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Disconnect :name?', ['name' => $account->display_name]) }}</flux:heading>

                            <flux:subheading>
                                {{ __('The stored credentials, discovered services, and sync history for this account are permanently deleted. Costs you entered by hand are kept.') }}
                            </flux:subheading>
                        </div>

                        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>

                            <flux:button variant="danger" wire:click="deleteAccount({{ $account->id }})">
                                {{ __('Disconnect account') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endforeach
        </div>
    @endif

    {{-- Least-privilege guidance, one card per registered provider. --}}
    @foreach ($this->schemas->options() as $providerKey => $label)
        @php($schema = $this->schemas->for($providerKey))
        <flux:card>
            <flux:heading size="lg">{{ __('Read-only :provider credentials', ['provider' => $label]) }}</flux:heading>

            <p class="mt-2 text-sm text-ink-secondary">
                {{ __($schema::help()) }}
            </p>

            @if ($schema::helpUrl() !== null)
                <p class="mt-2 text-sm text-ink-secondary">
                    <a href="{{ $schema::helpUrl() }}" target="_blank" rel="noopener noreferrer" class="font-medium text-ink underline decoration-line underline-offset-4 hover:decoration-line-strong">{{ __('How to create :provider credentials', ['provider' => $label]) }}</a>
                </p>
            @endif
        </flux:card>
    @endforeach

    {{-- The add/edit form lives in a right-side pop-out panel. --}}
    <flux:modal name="provider-form" variant="flyout" wire:model="panelOpen" class="w-full max-w-lg">
        <flux:heading size="lg" class="mb-2">
            @if ($editingAccountId !== null)
                {{ __('Replace :provider credentials', ['provider' => $this->providerLabelFor($this->providerKey)]) }}
            @else
                {{ __('Connect :provider', ['provider' => $this->providerLabelFor($this->providerKey)]) }}
            @endif
        </flux:heading>

        <p class="mb-6 text-sm text-ink-secondary">
            {{ __('Read-only access only — Lafiel never calls a mutating provider endpoint.') }}
        </p>

        <form wire:submit="{{ $editingAccountId === null ? 'connect' : 'update' }}" class="space-y-6">
            @if ($editingAccountId === null)
                <flux:select wire:model="providerKey" :label="__('Provider')" data-test="provider-select">
                    @foreach ($this->schemas->options() as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="displayName" :label="__('Display name')" required placeholder="{{ __('Main account') }}" />

            @foreach ($this->activeSchema::fields() as $field)
                @if ($field->type === 'select')
                    <flux:select wire:model="credential.{{ $field->name }}" :label="__($field->label)">
                        @foreach ($field->options as $option)
                            <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:input
                        wire:model="credential.{{ $field->name }}"
                        :label="__($field->label)"
                        :type="$field->type === 'password' ? 'password' : 'text'"
                        required
                        autocomplete="off"
                        :placeholder="$field->placeholder !== null ? __($field->placeholder) : null"
                    />
                @endif
            @endforeach

            @if ($editingAccountId !== null)
                <p class="text-sm text-ink-muted">
                    {{ __('Secret fields are never shown — enter them again to replace the stored values.') }}
                </p>
            @endif

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" type="submit" data-test="save-provider-button">
                    {{ $editingAccountId === null ? __('Connect account') : __('Save credentials') }}
                </flux:button>

                <flux:button type="button" wire:click="cancelPanel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
