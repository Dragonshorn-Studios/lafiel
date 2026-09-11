<?php

use App\Domain\Providers\Models\ProviderAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * Sync state for the shell: green when every enabled account synced
     * within the freshness window, amber when evidence is going stale,
     * muted when nothing has synced yet.
     */
    #[Computed]
    public function accounts(): Collection
    {
        return ProviderAccount::query()->where('enabled', true)->get();
    }

    #[Computed]
    public function lastSyncedAt(): ?CarbonImmutable
    {
        $max = $this->accounts->max('last_success_at');

        return $max === null ? null : new CarbonImmutable($max);
    }

    #[Computed]
    public function allFresh(): bool
    {
        if ($this->accounts->isEmpty()) {
            return false;
        }

        $freshnessDays = (int) config('costs.freshness_days', 7);

        return $this->accounts->every(
            fn (ProviderAccount $account): bool => $account->last_success_at !== null
                && $account->last_success_at->gte(now()->subDays($freshnessDays)),
        );
    }
}; ?>

<div {{ $attributes->merge(['class' => 'flex items-center gap-2 text-sm']) }}>
    @if ($this->lastSyncedAt !== null)
        <span
            class="size-2 rounded-full {{ $this->allFresh ? 'bg-success' : 'bg-attention' }}"
            aria-hidden="true"
        ></span>
        <span class="{{ isset($onCommand) && $onCommand ? 'text-on-command/75' : 'text-ink-secondary' }}">
            {{ __('Synced :ago', ['ago' => $this->lastSyncedAt->diffForHumans()]) }}
        </span>
    @else
        <span class="size-2 rounded-full bg-ink-muted" aria-hidden="true"></span>
        <span class="{{ isset($onCommand) && $onCommand ? 'text-on-command/75' : 'text-ink-secondary' }}">
            {{ __('Not synced yet') }}
        </span>
    @endif
</div>
