<?php

use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Costs\Projection\ProjectionFormatter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overview')] class extends Component {
    /**
     * The projection is the only calculator: the Overview renders what
     * CostProjector produces and never sums costs by itself.
     */
    #[Computed]
    public function summary(): array
    {
        $projector = app(CostProjector::class);
        $formatter = app(ProjectionFormatter::class);

        $result = $projector->project(now());

        return [
            'monthly' => $formatter->monthly($result),
            'annual' => $formatter->annual($result),
            'unknownCount' => $result->unknownCount,
            'estimateCount' => $result->estimateCount,
            'staleCount' => $result->staleCount,
            'sharedUnallocatedCount' => $result->sharedUnallocatedCount,
        ];
    }
}; ?>
<section class="w-full space-y-4">
    <flux:heading size="h2">{{ __('Cost overview') }}</flux:heading>

    <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
        <flux:heading>{{ __('Monthly') }}</flux:heading>
        <p class="font-mono text-3xl font-semibold tabular-nums" data-test="overview-monthly">{{ $this->summary['monthly'] }}</p>

        <flux:heading class="mt-4">{{ __('Annual') }}</flux:heading>
        <p class="font-mono text-xl font-semibold tabular-nums" data-test="overview-annual">{{ $this->summary['annual'] }}</p>

        <div class="mt-4 flex flex-wrap gap-2 text-sm text-zinc-600 dark:text-zinc-400">
            @if ($this->summary['unknownCount'] > 0)
                <span class="rounded-full bg-zinc-100 px-3 py-1 dark:bg-neutral-800" data-test="overview-unknown">
                    {{ __(':n unknown', ['n' => $this->summary['unknownCount']]) }}
                </span>
            @endif
            @if ($this->summary['estimateCount'] > 0)
                <span class="rounded-full bg-zinc-100 px-3 py-1 dark:bg-neutral-800">
                    {{ __(':n estimates', ['n' => $this->summary['estimateCount']]) }}
                </span>
            @endif
            @if ($this->summary['staleCount'] > 0)
                <span class="rounded-full bg-zinc-100 px-3 py-1 dark:bg-neutral-800">
                    {{ __(':n stale', ['n' => $this->summary['staleCount']]) }}
                </span>
            @endif
            @if ($this->summary['sharedUnallocatedCount'] > 0)
                <span class="rounded-full bg-zinc-100 px-3 py-1 dark:bg-neutral-800">
                    {{ __(':n shared unallocated', ['n' => $this->summary['sharedUnallocatedCount']]) }}
                </span>
            @endif
        </div>
    </div>

    <div class="flex gap-2 text-sm">
        <flux:link :href="route('costs.index')">{{ __('Manage costs') }}</flux:link>
    </div>
</section>
