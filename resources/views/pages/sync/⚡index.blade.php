<?php

use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Actions\RequestSync;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Sync activity')] class extends Component {
    use WithPagination;

    /**
     * Filters, kept as strings because the selects submit '' for "all".
     */
    public string $accountFilter = '';

    public string $statusFilter = '';

    public function updatedAccountFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function schemas(): CredentialSchemas
    {
        return app(CredentialSchemas::class);
    }

    /**
     * Runs waiting for a worker or executing now — the queue the page
     * is named for. The active-run index caps this at one per account,
     * so the list stays short by construction.
     *
     * @return Collection<int, SyncRun>
     */
    #[Computed]
    public function activeRuns(): Collection
    {
        return SyncRun::query()
            ->with('providerAccount')
            ->whereIn('status', [SyncStatus::Queued->value, SyncStatus::Running->value])
            ->orderBy('id')
            ->get();
    }

    /**
     * The full history, newest first; filters apply only here, so the
     * active section always shows the truth regardless of filtering.
     *
     * @return LengthAwarePaginator<int, SyncRun>
     */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        return SyncRun::query()
            ->with('providerAccount')
            ->when($this->accountFilter !== '', fn ($query) => $query->where('provider_account_id', (int) $this->accountFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->latest('id')
            ->paginate(15);
    }

    /**
     * Accounts for the filter select, in display order.
     *
     * @return Collection<int, ProviderAccount>
     */
    #[Computed]
    public function filterAccounts(): Collection
    {
        return ProviderAccount::query()
            ->orderBy('display_name')
            ->get(['id', 'display_name']);
    }

    /**
     * When the daily schedule fires next, from the same config the
     * schedule itself reads.
     */
    #[Computed]
    public function nextScheduledAt(): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('sync.scheduled_at', '04:00')));

        $now = CarbonImmutable::now(config('app.timezone'));
        $next = $now->setTime($hour, $minute);

        return $next->lessThan($now) ? $next->addDay() : $next;
    }

    #[Computed]
    public function lastSuccessfulAt(): ?CarbonImmutable
    {
        $finishedAt = SyncRun::query()
            ->where('status', SyncStatus::Succeeded->value)
            ->max('finished_at');

        return $finishedAt === null ? null : new CarbonImmutable($finishedAt);
    }

    /**
     * Queue a fresh manual run for the account behind a failed one —
     * through the shared entry point, so a retry can never start a
     * parallel run.
     */
    public function retry(int $runId): void
    {
        $run = SyncRun::query()->with('providerAccount')->findOrFail($runId);
        $account = $run->providerAccount;

        if (! $account->enabled) {
            Flux::toast(variant: 'warning', text: __('Sync is paused for this account.'));

            return;
        }

        $queued = app(RequestSync::class)->request($account, 'manual');

        if ($queued === null) {
            Flux::toast(variant: 'info', text: __('A sync is already running for this account.'));

            return;
        }

        if ($queued->status === SyncStatus::Failed) {
            Flux::toast(variant: 'danger', text: __('The sync could not be queued. Check the logs.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Sync queued.'));
    }

    public function formatAt(?CarbonImmutable $at): ?string
    {
        return $at?->timezone(config('app.timezone'))->format('Y-m-d H:i');
    }

    /**
     * Wall time of a run: started to finished, or started to now while
     * it is still running.
     */
    public function durationOf(SyncRun $run): ?string
    {
        if ($run->started_at === null) {
            return null;
        }

        $seconds = max(0, (int) $run->started_at->diffInSeconds($run->finished_at ?? new CarbonImmutable));

        return $seconds < 60
            ? sprintf('%ds', $seconds)
            : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * The per-phase numbers as one compact line for the table.
     *
     * @param  array<string, mixed>|null  $counts
     */
    public function countsSummary(?array $counts): string
    {
        if ($counts === null) {
            return '—';
        }

        $parts = [];

        $inventory = $counts['inventory'] ?? null;
        if (is_array($inventory)) {
            $parts[] = sprintf(
                '%s %d seen · %d new · %d updated',
                __('inventory'),
                (int) ($inventory['seen'] ?? 0),
                (int) ($inventory['created'] ?? 0),
                (int) ($inventory['updated'] ?? 0),
            );
        }

        $costFacts = $counts['cost_facts'] ?? null;
        if (is_array($costFacts)) {
            $parts[] = sprintf(
                '%s %d seen · %d new',
                __('cost facts'),
                (int) ($costFacts['seen'] ?? 0),
                (int) ($costFacts['created'] ?? 0),
            );
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}; ?>

<section class="w-full space-y-6" data-test="sync-activity">
    {{-- Live while work is in flight; the element vanishes with the last
         active run, so an idle page never polls. --}}
    @if ($this->activeRuns->isNotEmpty())
        <div class="hidden" wire:poll.10s aria-hidden="true"></div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="h1">{{ __('Sync activity') }}</flux:heading>

            <flux:subheading>
                {{ __('Next scheduled sync :at', ['at' => $this->formatAt($this->nextScheduledAt)]) }}

                @if ($this->lastSuccessfulAt !== null)
                    · {{ __('last successful :at', ['at' => $this->formatAt($this->lastSuccessfulAt)]) }}
                @endif
            </flux:subheading>
        </div>

        <flux:button size="sm" icon="arrow-path" wire:click="$refresh" data-test="refresh-button">
            {{ __('Refresh') }}
        </flux:button>
    </div>

    @if ($this->activeRuns->isNotEmpty())
        <flux:card data-test="active-syncs" class="space-y-3">
            <flux:heading size="sm">{{ __('In progress') }}</flux:heading>

            @foreach ($this->activeRuns as $run)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm" wire:key="active-{{ $run->id }}" data-test="active-sync-run">
                    <span class="font-medium">{{ $run->providerAccount->display_name }}</span>

                    <x-imperial.sync-status-badge :status="$run->status" />

                    @if ($run->stage !== null)
                        <span class="text-ink-secondary">{{ $run->stage->label() }}</span>
                    @endif

                    <span class="text-ink-muted">{{ __('queued :at', ['at' => $this->formatAt($run->created_at)]) }}</span>

                    @if ($run->started_at !== null)
                        <span class="text-ink-muted">{{ __('started :at', ['at' => $this->formatAt($run->started_at)]) }}</span>
                        <span class="text-ink-muted">{{ __('elapsed :duration', ['duration' => $this->durationOf($run)]) }}</span>
                    @endif
                </div>
            @endforeach
        </flux:card>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="accountFilter" :label="__('Account')" class="w-48" data-test="account-filter">
            <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>

            @foreach ($this->filterAccounts as $account)
                <flux:select.option :value="(string) $account->id">{{ $account->display_name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="statusFilter" :label="__('Status')" class="w-40" data-test="status-filter">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>

            @foreach (\App\Domain\Sync\Enums\SyncStatus::cases() as $status)
                <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->runs->isEmpty())
        <x-imperial.empty-state :hint="__('Queued, running, and finished synchronizations appear here. Start one from the Providers page.')" />
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Account') }}</flux:table.column>
                <flux:table.column>{{ __('Trigger') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Timeline') }}</flux:table.column>
                <flux:table.column>{{ __('Result') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->runs as $run)
                    @php($error = $run->summary['error'] ?? null)
                    @php($warnings = $run->summary['warnings'] ?? [])

                    <flux:table.row :key="$run->id" data-test="sync-run">
                        <flux:table.cell>
                            <span class="block font-medium">{{ $run->providerAccount->display_name }}</span>
                            <span class="block text-xs text-ink-muted">
                                {{ $this->schemas->labelFor($run->providerAccount->provider_key) }} · #{{ $run->id }}
                            </span>
                        </flux:table.cell>

                        <flux:table.cell>{{ __($run->trigger) }}</flux:table.cell>

                        <flux:table.cell>
                            <div class="space-y-1">
                                <x-imperial.sync-status-badge :status="$run->status" />

                                @if ($run->stage !== null)
                                    <span class="block text-xs text-ink-muted" title="{{ $run->stage->label() }}">
                                        {{ $run->stage->label() }}
                                    </span>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="text-xs">
                                <div class="text-ink-secondary">{{ __('Queued :at', ['at' => $this->formatAt($run->created_at)]) }}</div>

                                @if ($run->started_at !== null)
                                    <div class="text-ink-secondary">{{ __('Started :at', ['at' => $this->formatAt($run->started_at)]) }}</div>
                                @endif

                                @if ($run->finished_at !== null)
                                    <div class="text-ink-secondary">
                                        {{ __('Finished :at', ['at' => $this->formatAt($run->finished_at)]) }}
                                        @if ($this->durationOf($run) !== null)
                                            ({{ $this->durationOf($run) }})
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell class="max-w-72">
                            @if ($error !== null)
                                <span class="block text-sm text-danger" title="{{ $error }}" data-test="sync-run-error">
                                    {{ \Illuminate\Support\Str::limit($error, 90) }}
                                </span>
                            @else
                                <span class="block text-sm text-ink-secondary" title="{{ $this->countsSummary($run->counts) }}">
                                    {{ $this->countsSummary($run->counts) }}
                                </span>
                            @endif

                            @if ($warnings !== [])
                                <span class="block text-xs text-attention" title="{{ implode(' | ', $warnings) }}" data-test="sync-run-warnings">
                                    {{ __(':count warning(s)', ['count' => count($warnings)]) }}
                                </span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($run->status === \App\Domain\Sync\Enums\SyncStatus::Failed && $run->providerAccount->enabled)
                                <flux:button size="xs" wire:click="retry({{ $run->id }})" data-test="retry-sync-button">
                                    {{ __('Retry') }}
                                </flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:pagination :paginator="$this->runs" />
    @endif
</section>
