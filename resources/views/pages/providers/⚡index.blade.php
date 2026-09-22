<?php

use App\Domain\Providers\Actions\ClearProviderSyncedData;
use App\Domain\Providers\Actions\ConnectProviderAccount;
use App\Domain\Providers\Actions\DeleteProviderAccount;
use App\Domain\Providers\Actions\SetProviderAccountEnabled;
use App\Domain\Providers\Actions\TestProviderConnection;
use App\Domain\Providers\Actions\UpdateProviderCredentials;
use App\Domain\Providers\Actions\VerifyCredentials;
use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Enums\ConnectionStatus;
use App\Domain\Providers\Exceptions\UnsupportedProviderException;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Actions\RequestSync;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
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
     * The account whose last synchronization the details modal shows.
     */
    public ?int $syncDetailsAccountId = null;

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

    /**
     * Outcome of the last draft-credential test in the panel. Voided by
     * any credential edit, a provider swap, or reopening the form — it
     * certifies exactly the field values it probed.
     *
     * @var array{status: string, message: string}|null
     */
    public ?array $draftCheck = null;

    #[Computed]
    public function schemas(): CredentialSchemas
    {
        return app(CredentialSchemas::class);
    }

    /**
     * The credential schema behind the panel's selected provider.
     * `providerKey` is a public Livewire property — a client can set
     * it to anything — so rendering falls back to a registered schema
     * instead of erroring; the connect/update actions still reject an
     * unknown key explicitly.
     */
    #[Computed]
    public function activeSchema(): string
    {
        try {
            return $this->schemas->for($this->providerKey);
        } catch (UnsupportedProviderException) {
            return $this->schemas->for((string) array_key_first($this->schemas->options()));
        }
    }

    public function updatedProviderKey(): void
    {
        $this->resetCredentialFields();
        $this->resetValidation();
    }

    public function updatedCredential(): void
    {
        // Editing any credential field invalidates the last draft test.
        $this->draftCheck = null;
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
                if (! $field->isSecret() && isset($payload[$field->name])) {
                    $this->credential[$field->name] = (string) $payload[$field->name];
                }
            }
        }

        $this->resetValidation();
        $this->panelOpen = true;
    }

    public function connect(): void
    {
        $validated = $this->validate($this->formRules(), attributes: $this->formAttributes());

        try {
            app(ConnectProviderAccount::class)->connect($this->providerKey, $this->actionInput($validated));
        } catch (UnsupportedProviderException) {
            // `providerKey` is client-editable; an unknown one is a
            // form error, not a server error.
            $this->addError('providerKey', __('This provider is not available.'));

            return;
        }

        $this->closePanel();

        Flux::toast(variant: 'success', text: __('Provider connected. Run a connection test to verify the credentials.'));
    }

    public function update(): void
    {
        $account = $this->account((int) $this->editingAccountId);

        $validated = $this->validate($this->formRules(), attributes: $this->formAttributes());

        try {
            app(UpdateProviderCredentials::class)->update($account, $this->actionInput($validated));
        } catch (UnsupportedProviderException) {
            $this->addError('providerKey', __('This provider is not available.'));

            return;
        }

        $this->closePanel();

        Flux::toast(variant: 'success', text: __('Credentials replaced. Run a connection test to verify them.'));
    }

    public function cancelPanel(): void
    {
        $this->closePanel();
    }

    /**
     * Test the panel's draft credentials through the same adapter
     * probe a stored credential takes, before anything is saved: a
     * transient account on the add form, the persisted account on
     * edit. A draft pass never writes `verified_at` — only the saved
     * credential's own test does that.
     */
    public function testCredentials(): void
    {
        $validated = $this->validate($this->credentialRules(), attributes: $this->formAttributes());

        $account = $this->editingAccountId !== null
            ? $this->account($this->editingAccountId)
            : new ProviderAccount(['provider_key' => $this->providerKey]);

        try {
            $check = app(TestProviderConnection::class)->checkPayload(
                $account,
                $this->activeSchema::payload($validated['credential']),
            );
        } catch (UnsupportedProviderException) {
            // `providerKey` is client-editable; an unknown one is a
            // form error, not a server error.
            $this->addError('providerKey', __('This provider is not available.'));

            return;
        }

        $this->draftCheck = [
            'status' => $check->status->value,
            'message' => $check->warning ?? __('The provider accepted the credentials.'),
        ];
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
        // The details modal can outlive the account it describes — a
        // disconnect in another tab leaves its buttons armed. A stale
        // id should toast, not throw.
        $account = ProviderAccount::query()->find($accountId);

        if ($account === null) {
            Flux::toast(variant: 'warning', text: __('This provider account no longer exists.'));

            return;
        }

        if (! $account->enabled) {
            Flux::toast(variant: 'warning', text: __('Sync is paused for this account.'));

            return;
        }

        $run = app(RequestSync::class)->request($account, 'manual');

        if ($run === null) {
            Flux::toast(variant: 'info', text: __('A sync is already running for this account.'));

            return;
        }

        if ($run->status === SyncStatus::Failed) {
            Flux::toast(variant: 'danger', text: __('The sync could not be queued. Check the logs.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Sync queued.'));
    }

    /**
     * Wipe discovered services, provider charges, and sync history
     * while keeping the connection. Blocked while a sync is active.
     */
    public function clearSyncedData(int $accountId): void
    {
        $account = $this->account($accountId);

        if (! app(ClearProviderSyncedData::class)->clear($account)) {
            Flux::toast(variant: 'warning', text: __('A sync is still running for this account. Wait for it to finish, then try again.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Synced data cleared. Credentials were kept — you can sync again from a clean slate.'));
    }

    public function deleteAccount(int $accountId): void
    {
        $account = $this->account($accountId);

        if (! app(DeleteProviderAccount::class)->delete($account)) {
            Flux::toast(variant: 'warning', text: __('A sync is still running for this account. Wait for it to finish, then try again.'));

            return;
        }

        unset($this->connectionChecks[$accountId]);

        if ($this->editingAccountId === $accountId) {
            $this->closePanel();
        }

        if ($this->syncDetailsAccountId === $accountId) {
            $this->syncDetailsAccountId = null;
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
     * Open the details modal for this account's latest
     * synchronization; the run itself is fetched fresh at render time
     * by the syncDetailsRun() computed.
     */
    public function openSyncDetails(int $accountId): void
    {
        $this->syncDetailsAccountId = $accountId;

        Flux::modal('sync-details')->show();
    }

    #[Computed]
    public function syncDetailsAccount(): ?ProviderAccount
    {
        if ($this->syncDetailsAccountId === null) {
            return null;
        }

        return ProviderAccount::query()->find($this->syncDetailsAccountId);
    }

    /**
     * The account's most recent run, fetched fresh by the stored id so
     * the modal does not depend on the accounts() listing.
     */
    #[Computed]
    public function syncDetailsRun(): ?SyncRun
    {
        return $this->syncDetailsAccount?->latestSyncRun()->first();
    }

    /**
     * Accessible label for a card's last-run badge: names the state and
     * says the badge opens the details dialog.
     */
    public function badgeLabel(?SyncRun $lastRun): string
    {
        return $lastRun !== null
            ? __('Last run: :status — show synchronization details', ['status' => $lastRun->status->label()])
            : __('Never synced — show synchronization details');
    }

    /**
     * The run's display summary: the error to headline — the stored
     * error, or the first warning of legacy runs that predate the
     * error key — plus the remaining notes.
     *
     * @return array{error: ?string, warnings: list<string>}
     */
    public function syncDetailsSummary(SyncRun $run): array
    {
        $error = $run->summary['error'] ?? null;
        $warnings = $run->summary['warnings'] ?? [];

        if ($error === null && $warnings !== [] && $run->status === SyncStatus::Failed) {
            $error = array_shift($warnings);
        }

        return ['error' => $error, 'warnings' => $warnings];
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
        return ['displayName' => ['required', 'string', 'max:255'], ...$this->credentialRules()];
    }

    /**
     * The draft credential test validates only the schema's fields —
     * the display name is irrelevant until the credentials are saved.
     *
     * @return array<string, list<string>>
     */
    private function credentialRules(): array
    {
        $rules = [];

        foreach ($this->activeSchema::rules() as $key => $cases) {
            $rules['credential.'.$key] = $cases;
        }

        return $rules;
    }

    /**
     * Human-readable attribute names for validation messages, so an
     * error reads "the endpoint field" instead of the dotted payload
     * key the form binds.
     *
     * @return array<string, string>
     */
    private function formAttributes(): array
    {
        $attributes = ['displayName' => __('Display name')];

        foreach ($this->activeSchema::fields() as $field) {
            $attributes['credential.'.$field->name] = __($field->label);
        }

        return $attributes;
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
        $this->draftCheck = null;

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

                        {{-- The last-run status opens the details modal; it is a
                             real button so it reads as clickable and works from
                             the keyboard (issue #49). --}}
                        <button
                            type="button"
                            wire:click="openSyncDetails({{ $account->id }})"
                            class="group inline-flex cursor-pointer items-center gap-1.5 rounded-control px-1 py-0.5 text-xs text-ink-muted transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-info"
                            aria-haspopup="dialog"
                            aria-label="{{ $this->badgeLabel($account->latestSyncRun) }}"
                            data-test="last-run-status"
                        >
                            @if ($account->latestSyncRun !== null)
                                <span>{{ __('Last run') }}</span>
                                <x-imperial.sync-status-badge :status="$account->latestSyncRun->status" />
                            @else
                                <span>{{ __('Never synced') }}</span>
                                <flux:icon.clock variant="mini" class="size-3.5" />
                            @endif

                            {{-- The chevron swaps for a spinner while the details round-trip runs. --}}
                            <flux:icon.arrow-path variant="mini" class="size-3.5 animate-spin" wire:loading wire:target="openSyncDetails({{ $account->id }})" data-test="sync-details-loading" />

                            <flux:icon.chevron-right variant="micro" class="size-3 transition-transform group-hover:translate-x-0.5 rtl:rotate-180" wire:loading.remove wire:target="openSyncDetails({{ $account->id }})" />
                        </button>
                    </div>

                    <p class="mt-2 text-sm text-ink-secondary">
                        {{ $this->schemas->labelFor($account->provider_key) }} · {{ $this->credentialSummary($account) }}
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

                        <flux:modal.trigger name="clear-synced-{{ $account->id }}">
                            <flux:button size="sm" variant="ghost" data-test="clear-synced-button">
                                {{ __('Clear synced data') }}
                            </flux:button>
                        </flux:modal.trigger>

                        <flux:modal.trigger name="delete-provider-{{ $account->id }}">
                            <flux:button size="sm" variant="danger" data-test="delete-provider-button">
                                {{ __('Disconnect') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </flux:card>

                <flux:modal name="clear-synced-{{ $account->id }}" class="max-w-lg">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Clear synced data for :name?', ['name' => $account->display_name]) }}</flux:heading>

                            <flux:subheading>
                                {{ __('Discovered services, provider charges, and sync history for this account are permanently deleted. Credentials stay. Independent charges you entered by hand are kept. Then you can sync again from a clean slate.') }}
                            </flux:subheading>
                        </div>

                        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>

                            <flux:button variant="danger" wire:click="clearSyncedData({{ $account->id }})" data-test="confirm-clear-synced">
                                {{ __('Clear synced data') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>

                <flux:modal name="delete-provider-{{ $account->id }}" class="max-w-lg">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Disconnect :name?', ['name' => $account->display_name]) }}</flux:heading>

                            <flux:subheading>
                                {{ __('The stored credentials, discovered services, provider charges, and sync history for this account are permanently deleted. Independent charges you entered by hand are kept.') }}
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

    {{-- One modal serves every card's badge: the account whose details it
         shows is the last one whose badge was clicked (issue #49). --}}
    <flux:modal name="sync-details" class="max-w-lg" data-test="sync-details-modal">
        <div class="space-y-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('Last synchronization') }}</flux:heading>

                    <flux:subheading>
                        @if ($this->syncDetailsAccount !== null)
                            {{ $this->syncDetailsAccount->display_name }} · {{ $this->schemas->labelFor($this->syncDetailsAccount->provider_key) }}
                        @endif
                    </flux:subheading>
                </div>

                <flux:modal.close class="shrink-0">
                    <flux:button variant="ghost" size="sm" icon="x-mark" :aria-label="__('Close')" />
                </flux:modal.close>
            </div>

            @if ($this->syncDetailsAccount === null)
                <p class="text-sm text-ink-secondary">{{ __('This provider account no longer exists.') }}</p>
            @elseif ($this->syncDetailsRun === null)
                <x-imperial.empty-state :hint="__('No synchronization has run for this account yet. Start one to import its services and renewal quotes.')" class="py-8" data-test="sync-details-empty">
                    <flux:button size="sm" variant="primary" wire:click="syncNow({{ $this->syncDetailsAccount->id }})">
                        {{ __('Sync now') }}
                    </flux:button>
                </x-imperial.empty-state>
            @else
                @php($run = $this->syncDetailsRun)
                @php($summary = $this->syncDetailsSummary($run))

                <div class="flex flex-wrap items-center gap-2">
                    <x-imperial.sync-status-badge :status="$run->status" />

                    <span class="text-xs text-ink-muted">
                        #{{ $run->id }} · {{ __($run->trigger) }}
                    </span>

                    @if ($run->stage !== null)
                        <span class="text-xs text-ink-secondary">
                            {{ $run->status === SyncStatus::Failed ? __('failed during :stage', ['stage' => $run->stage->label()]) : $run->stage->label() }}
                        </span>
                    @endif
                </div>

                <dl class="grid gap-x-4 gap-y-1.5 text-sm sm:grid-cols-2" data-test="sync-details-timeline">
                    <div class="flex justify-between gap-3 sm:justify-start">
                        <dt class="text-ink-secondary">{{ __('Queued') }}</dt>
                        <dd>{{ $run->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</dd>
                    </div>

                    @if ($run->started_at !== null)
                        <div class="flex justify-between gap-3 sm:justify-start">
                            <dt class="text-ink-secondary">{{ __('Started') }}</dt>
                            <dd>{{ $run->started_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</dd>
                        </div>
                    @endif

                    @if ($run->finished_at !== null)
                        <div class="flex justify-between gap-3 sm:justify-start">
                            <dt class="text-ink-secondary">{{ __('Finished') }}</dt>
                            <dd>
                                {{ $run->finished_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                @if ($run->started_at !== null)
                                    ({{ $run->started_at->diffInSeconds($run->finished_at) }}s)
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($summary['error'] !== null)
                    <div class="rounded-control border border-danger/40 bg-danger/10 p-3 text-sm text-danger" data-test="sync-details-error">
                        {{ $summary['error'] }}
                    </div>
                @endif

                @if ($summary['warnings'] !== [])
                    <div>
                        <p class="text-xs font-medium text-ink-secondary">{{ __('Notes from the run') }}</p>

                        <ul class="mt-1 max-h-40 space-y-1 overflow-y-auto rounded-control border border-line bg-surface-subtle p-3 text-xs text-ink-secondary" data-test="sync-details-warnings">
                            @foreach ($summary['warnings'] as $warning)
                                <li wire:key="warning-{{ $loop->index }}">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @isset($run->counts['inventory'])
                    <p class="text-xs text-ink-secondary">
                        {{ __('inventory') }}: {{ $run->counts['inventory']['seen'] ?? 0 }} {{ __('seen') }}, {{ $run->counts['inventory']['created'] ?? 0 }} {{ __('new') }}
                    </p>
                @endisset

                @isset($run->counts['cost_facts'])
                    <p class="text-xs text-ink-secondary">
                        {{ __('cost facts') }}: {{ $run->counts['cost_facts']['seen'] ?? 0 }} {{ __('seen') }}, {{ $run->counts['cost_facts']['created'] ?? 0 }} {{ __('new') }}
                    </p>
                @endisset

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-4">
                    <a href="{{ route('syncs.index') }}" wire:navigate class="text-sm font-medium text-ink underline decoration-line underline-offset-4 hover:decoration-line-strong">
                        {{ __('View all sync activity') }}
                    </a>

                    <div class="flex items-center gap-2">
                        @if ($run->status === SyncStatus::Failed && $this->syncDetailsAccount->enabled)
                            <flux:button size="sm" variant="primary" wire:click="syncNow({{ $this->syncDetailsAccount->id }})" data-test="sync-details-retry">
                                {{ __('Retry sync') }}
                            </flux:button>
                        @endif

                        <flux:modal.close>
                            <flux:button size="sm" variant="ghost">{{ __('Close') }}</flux:button>
                        </flux:modal.close>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- The add/edit form lives in a right-side pop-out panel. --}}
    <flux:modal name="provider-form" variant="flyout" wire:model="panelOpen" class="w-full max-w-lg">
        <flux:heading size="lg" class="mb-2">
            @if ($editingAccountId !== null)
                {{ __('Replace :provider credentials', ['provider' => $this->schemas->labelFor($this->providerKey)]) }}
            @else
                {{ __('Connect :provider', ['provider' => $this->schemas->labelFor($this->providerKey)]) }}
            @endif
        </flux:heading>

        <p class="mb-6 text-sm text-ink-secondary">
            {{ __('Read-only access only — Lafiel never calls a mutating provider endpoint.') }}
        </p>

        <form wire:submit="{{ $editingAccountId === null ? 'connect' : 'update' }}" class="space-y-6">
            @if ($editingAccountId === null)
                {{-- .live: the provider drives which credential fields and
                     which notes render — a deferred model would only sync
                     on submit, leaving the panel stale until then. --}}
                <flux:select wire:model.live="providerKey" :label="__('Provider')" data-test="provider-select">
                    @foreach ($this->schemas->options() as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="displayName" :label="__('Display name')" required placeholder="{{ __('Main account') }}" />

            @foreach ($this->activeSchema::fields() as $field)
                @if ($field->isSelect())
                    <flux:select
                        wire:model="credential.{{ $field->name }}"
                        :label="__($field->label)"
                        :placeholder="$field->placeholder !== null ? __($field->placeholder) : null"
                        required
                    >
                        @foreach ($field->options as $value => $label)
                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:input
                        wire:model="credential.{{ $field->name }}"
                        :label="__($field->label)"
                        :type="$field->isSecret() ? 'password' : 'text'"
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

            {{-- The last draft test's outcome, styled like the account
                 cards' connection checks; any credential edit voids it. --}}
            @if ($draftCheck !== null)
                <p class="text-sm" data-test="draft-check">
                    @if ($draftCheck['status'] === ConnectionStatus::Connected->value)
                        <flux:icon.check-circle variant="mini" class="mr-1 inline size-4 text-success" />
                    @elseif ($draftCheck['status'] === ConnectionStatus::Rejected->value)
                        <flux:icon.exclamation-triangle variant="mini" class="mr-1 inline size-4 text-danger" />
                    @else
                        <flux:icon.clock variant="mini" class="mr-1 inline size-4 text-attention" />
                    @endif

                    {{ $draftCheck['message'] }}
                </p>
            @endif

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" type="submit" data-test="save-provider-button">
                    {{ $editingAccountId === null ? __('Connect account') : __('Save credentials') }}
                </flux:button>

                {{-- Probes the entered credentials through the same
                     adapter check a saved account takes, without
                     storing them first. --}}
                <flux:button type="button" wire:click="testCredentials" data-test="test-draft-button">
                    {{ __('Test credentials') }}
                </flux:button>

                <flux:button type="button" wire:click="cancelPanel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>

        {{-- Least-privilege guidance for the selected provider, at the
             bottom of the panel; it follows the provider select. --}}
        <div class="mt-6 rounded-card border border-line bg-surface-subtle p-4 text-sm text-ink-secondary" data-test="provider-help">
            <p class="font-medium text-ink">{{ __('Read-only :provider credentials', ['provider' => $this->schemas->labelFor($this->providerKey)]) }}</p>

            <p class="mt-2">
                {{ __($this->activeSchema::help()) }}
            </p>

            {{-- The provider's setup guide, when its credentials take
                 more than "create a token": ordered steps with the
                 technical detail set off in monospace. --}}
            @if ($this->activeSchema::helpSteps() !== [])
                <ol class="mt-4 list-decimal space-y-3 pl-5" data-test="provider-help-steps">
                    @foreach ($this->activeSchema::helpSteps() as $step)
                        <li wire:key="help-step-{{ $loop->index }}">
                            <span>{{ __($step->body) }}</span>

                            @if ($step->lines !== [])
                                <div class="mt-1.5 space-y-1 rounded-control border border-line bg-surface p-2.5 font-mono text-xs leading-relaxed text-ink-secondary">
                                    @foreach ($step->lines as $line)
                                        <p class="break-all">{{ $line }}</p>
                                    @endforeach
                                </div>
                            @endif

                            @if ($step->url !== null)
                                <a href="{{ $step->url }}" target="_blank" rel="noopener noreferrer" class="mt-1.5 inline-block font-medium text-ink underline decoration-line underline-offset-4 hover:decoration-line-strong">
                                    {{ __($step->urlLabel ?? 'Read more') }}
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($this->activeSchema::helpUrl() !== null)
                <p class="mt-2">
                    <a href="{{ $this->activeSchema::helpUrl() }}" target="_blank" rel="noopener noreferrer" class="font-medium text-ink underline decoration-line underline-offset-4 hover:decoration-line-strong">{{ __('How to create :provider credentials', ['provider' => $this->schemas->labelFor($this->providerKey)]) }}</a>
                </p>
            @endif
        </div>
    </flux:modal>
</section>
