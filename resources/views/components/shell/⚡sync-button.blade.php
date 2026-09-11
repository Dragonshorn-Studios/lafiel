<?php

use App\Domain\Providers\Models\ProviderAccount;
use App\Domain\Sync\Actions\RequestSync;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * One compact command: queue a sync for every enabled provider
     * account through the shared entry point. Never starts a parallel
     * run — a busy account simply reports as already syncing.
     */
    public function syncNow(): void
    {
        $queued = 0;
        $alreadyRunning = 0;

        foreach ($this->accounts as $account) {
            $run = app(RequestSync::class)->request($account, 'manual');

            if ($run === null) {
                $alreadyRunning++;

                continue;
            }

            $queued++;
        }

        if ($queued === 0 && $alreadyRunning === 0) {
            Flux::toast(variant: 'info', text: __('No provider accounts are enabled.'));

            return;
        }

        if ($queued > 0) {
            Flux::toast(variant: 'success', text: __(':n syncs queued.', ['n' => $queued]));
        }

        if ($alreadyRunning > 0) {
            Flux::toast(variant: 'info', text: __(':n were already syncing.', ['n' => $alreadyRunning]));
        }
    }

    /**
     * @return Collection<int, ProviderAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return ProviderAccount::query()->where('enabled', true)->get();
    }

    #[Computed]
    public function lastSyncedAt(): ?CarbonImmutable
    {
        $max = ProviderAccount::query()
            ->where('enabled', true)
            ->max('last_success_at');

        return $max === null ? null : new CarbonImmutable($max);
    }
}; ?>

<button
    type="button"
    wire:click="syncNow"
    data-test="sync-now-button"
    {{ $attributes->merge(['class' => 'inline-flex cursor-pointer items-center gap-2']) }}
>
    <flux:icon.arrow-path class="size-5 lg:hidden" aria-hidden="true" />
    <span class="hidden lg:inline">{{ __('Sync now') }}</span>
    <span class="lg:hidden sr-only">{{ __('Sync now') }}</span>
</button>
