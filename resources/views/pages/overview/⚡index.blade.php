<?php

use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Costs\Projection\ProjectionFormatter;
use App\Domain\Costs\Projection\ProjectionResult;
use App\Domain\Costs\Projection\SpendHistory;
use App\Domain\Costs\UpcomingRenewalWindow;
use App\Domain\Costs\UpcomingRenewals;
use App\Domain\Providers\UsageGaps;
use App\Domain\Support\ValueObjects\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overview')] class extends Component {
    /**
     * The projection is the only calculator: the Overview renders what
     * CostProjector and the read models produce and never sums costs
     * by itself.
     */
    #[Computed]
    public function projection(): ProjectionResult
    {
        return app(CostProjector::class)->project(now());
    }

    /**
     * Same keys the costs flows rely on.
     */
    #[Computed]
    public function summary(): array
    {
        $formatter = app(ProjectionFormatter::class);
        $result = $this->projection;

        return [
            'monthly' => $formatter->monthly($result),
            'annual' => $formatter->annual($result),
            'otherCurrencies' => $formatter->otherCurrencies($result),
            'unknownCount' => $result->unknownCount,
            'estimateCount' => $result->estimateCount,
            'staleCount' => $result->staleCount,
            'sharedUnallocatedCount' => $result->sharedUnallocatedCount,
        ];
    }

    #[Computed]
    public function displayCurrency(): string
    {
        return (string) config('costs.display_currency', 'PLN');
    }

    #[Computed]
    public function history(): array
    {
        return app(SpendHistory::class)->monthly(now());
    }

    #[Computed]
    public function split(): array
    {
        return $this->projection->providerSplit($this->displayCurrency);
    }

    #[Computed]
    public function upcoming(): UpcomingRenewalWindow
    {
        return app(UpcomingRenewals::class)->within(now());
    }

    /**
     * Provider accounts whose metered usage cannot be read — fixed
     * subscriptions alone, so their slice of the total is incomplete.
     *
     * @return list<array{account: string}>
     */
    #[Computed]
    public function usageGaps(): array
    {
        return app(UsageGaps::class)->all();
    }

    /**
     * Present one minor-unit amount in its currency.
     */
    public function major(int $minor, string $currency): string
    {
        return Money::ofMinor($minor, $currency)->majorAmount();
    }
}; ?>

<section class="w-full space-y-4">
    <flux:heading size="h1" data-test="overview-heading">{{ __('Overview') }}</flux:heading>

    {{-- --- Primary metrics --- --}}
    <div class="grid gap-px overflow-hidden rounded-card border border-line bg-line sm:grid-cols-2 xl:grid-cols-4">
        <div class="bg-surface p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ __('Monthly equivalent') }}</p>
            @php($monthlyTotal = $this->projection->forCurrency($this->displayCurrency))
            <p class="mt-2 font-mono text-[32px] font-semibold leading-[40px] tabular-nums text-cost-estimate" data-test="overview-monthly-figure">
                {{ $this->major($monthlyTotal?->monthlyMinor ?? 0, $this->displayCurrency) }}
            </p>
            <p class="font-mono text-sm text-ink-secondary">{{ $this->displayCurrency }}/mo</p>
        </div>

        <div class="bg-surface p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ __('Annual equivalent') }}</p>
            @php($annualTotal = $this->projection->forCurrency($this->displayCurrency))
            <p class="mt-2 font-mono text-[32px] font-semibold leading-[40px] tabular-nums text-cost-estimate" data-test="overview-annual-figure">
                {{ $this->major($annualTotal?->annualMinor ?? 0, $this->displayCurrency) }}
            </p>
            <p class="font-mono text-sm text-ink-secondary">{{ $this->displayCurrency }}/yr</p>
        </div>

        <div class="bg-surface p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ __('Upcoming 30d') }}</p>
            @php($upcomingTotal = $this->upcoming->totalFor($this->displayCurrency))
            <p class="mt-2 font-mono text-[32px] font-semibold leading-[40px] tabular-nums text-cost-estimate" data-test="overview-upcoming-figure">
                {{ $upcomingTotal === null ? '—' : $this->major($upcomingTotal, $this->displayCurrency) }}
            </p>
            <p class="font-mono text-sm text-ink-secondary">
                {{ $this->upcoming->rows === [] ? __('nothing renews') : $this->displayCurrency }}
            </p>
        </div>

        <div class="bg-surface p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ __('Coverage') }}</p>
            <p class="mt-2 font-mono text-[32px] font-semibold leading-[40px] tabular-nums text-ink" data-test="overview-coverage">
                {{ $this->projection->pricedCount() }}
                <span class="text-sm font-medium text-ink-secondary">
                    {{ __('priced / :n unknown', ['n' => $this->projection->unknownCount]) }}
                </span>
            </p>
            <p class="text-xs text-ink-muted">{{ __('Known costs only · normalized') }}</p>
        </div>
    </div>

    {{-- --- Incompleteness is a feature, never hidden --- --}}
    @if ($this->summary['unknownCount'] > 0)
        <div class="flex items-center gap-3 rounded-card border border-attention/40 bg-attention/10 px-4 py-3 text-sm text-ink" data-test="overview-unknown-warning">
            <flux:icon.exclamation-triangle variant="mini" class="size-5 shrink-0 text-attention" />
            <span>
                {{ __('Monthly total is incomplete — pricing is unknown for :n services.', ['n' => $this->summary['unknownCount']]) }}
            </span>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- --- Monthly burn --- --}}
        <div class="rounded-card border border-line bg-surface p-5">
            <div class="flex items-center justify-between">
                <flux:heading>{{ __('Monthly burn (:currency)', ['currency' => $this->displayCurrency]) }}</flux:heading>
                <span class="flex items-center gap-3 text-xs text-ink-muted">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block h-0.5 w-5 bg-info"></span> {{ __('Actual') }}
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block h-0.5 w-5 border-t-2 border-dashed border-info"></span> {{ __('Estimated') }}
                    </span>
                </span>
            </div>

            <div class="mt-4">
                <x-imperial.line-chart :points="$this->history" />
            </div>
        </div>

        {{-- --- Provider split --- --}}
        <div class="rounded-card border border-line bg-surface p-5">
            <flux:heading>{{ __('Spend by provider (:month)', ['month' => now()->translatedFormat('F Y')]) }}</flux:heading>

            @if ($this->split === [])
                <p class="mt-6 text-sm text-ink-secondary" data-test="overview-empty">
                    {{ __('No costs yet. Add a provider or a manual service.') }}
                </p>
            @else
                <div class="mt-4 space-y-4">
                    @php($maxShare = max($this->split))
                    @foreach ($this->split as $provider => $shareMinor)
                        <div class="flex items-center gap-3">
                            <span class="w-28 shrink-0 truncate text-sm font-medium text-ink">{{ $provider }}</span>
                            <span class="h-2 flex-1 rounded-full bg-surface-subtle">
                                <span
                                    class="block h-2 rounded-full bg-info"
                                    style="width: {{ $maxShare > 0 ? round(100 * $shareMinor / $maxShare) : 0 }}%"
                                ></span>
                            </span>
                            <span class="w-28 shrink-0 text-end font-mono text-sm tabular-nums text-ink">
                                {{ $this->major($shareMinor, $this->displayCurrency) }} {{ $this->displayCurrency }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 flex items-center justify-between border-t border-line pt-4">
                    <span class="text-sm font-semibold text-ink">{{ __('Total') }}</span>
                    <span class="font-mono text-sm font-semibold tabular-nums text-ink" data-test="overview-split-total">
                        {{ $this->summary['monthly'] }}
                    </span>
                </div>
            @endif
        </div>

        {{-- --- Upcoming renewals --- --}}
        <div class="rounded-card border border-line bg-surface p-5">
            <flux:heading>{{ __('Upcoming renewals') }}</flux:heading>

            @if ($this->upcoming->rows === [])
                <p class="mt-6 text-sm text-ink-secondary">{{ __('No renewals in the next 30 days.') }}</p>
            @else
                <flux:table class="mt-2">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Service') }}</flux:table.column>
                        <flux:table.column>{{ __('Provider') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Renewal date') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->upcoming->rows as $row)
                            <flux:table.row :key="$row['cost_item']->id">
                                <flux:table.cell class="font-medium">{{ $row['name'] }}</flux:table.cell>
                                <flux:table.cell class="text-ink-secondary">{{ $row['provider'] }}</flux:table.cell>
                                <flux:table.cell class="font-mono tabular-nums">
                                    {{ $row['amount']?->majorAmount() ?? __('unknown') }}
                                    @if ($row['amount'] !== null)
                                        {{ $row['amount']->currency }}
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="font-mono tabular-nums">{{ $row['renews_at']->format('Y-m-d') }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif

            <flux:link href="{{ route('costs.renewals') }}" class="mt-4 inline-block text-sm" wire:navigate>
                {{ __('View all renewals') }}
            </flux:link>
        </div>

        {{-- --- Data quality --- --}}
        <div class="rounded-card border border-line bg-surface p-5">
            <flux:heading>{{ __('Data quality') }}</flux:heading>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <div class="rounded-card bg-surface-subtle p-4 text-center">
                    <flux:icon.check-circle class="mx-auto size-6 text-success" />
                    <p class="mt-2 font-mono text-2xl font-semibold tabular-nums text-ink">{{ $this->projection->pricedCount() }}</p>
                    <p class="text-xs text-ink-secondary">{{ __('Priced') }}</p>
                </div>
                <div class="rounded-card bg-surface-subtle p-4 text-center">
                    <flux:icon.question-mark-circle class="mx-auto size-6 {{ $this->summary['unknownCount'] > 0 ? 'text-attention' : 'text-ink-muted' }}" />
                    <p class="mt-2 font-mono text-2xl font-semibold tabular-nums text-ink" data-test="overview-unknown-count">{{ $this->summary['unknownCount'] }}</p>
                    <p class="text-xs text-ink-secondary">{{ __('Unknown') }}</p>
                </div>
            </div>

            <div class="mt-5 flex items-center gap-2 border-t border-line pt-4 text-sm text-ink-secondary">
                <flux:icon.clock variant="mini" class="size-4" />
                <span>{{ __('Last successful sync') }}</span>
            </div>

            @foreach ($this->summary['otherCurrencies'] as $label)
                <p class="mt-3 font-mono text-sm tabular-nums text-ink-secondary" data-test="overview-other-currency">{{ $label }}</p>
            @endforeach

            @if ($this->summary['staleCount'] > 0)
                <p class="mt-3 text-sm text-attention" data-test="overview-stale">
                    {{ __(':n charges rest on stale evidence and will refresh on the next sync.', ['n' => $this->summary['staleCount']]) }}
                </p>
            @endif

            @if ($this->summary['sharedUnallocatedCount'] > 0)
                <p class="mt-3 text-sm text-ink-secondary" data-test="overview-shared">
                    {{ __(':n shared charges are not yet attributed to single services.', ['n' => $this->summary['sharedUnallocatedCount']]) }}
                </p>
            @endif

            @foreach ($this->usageGaps as $gap)
                <p class="mt-3 text-sm text-attention" data-test="overview-usage-gap">
                    {{ __(':account: metered usage is unavailable — only fixed subscriptions are counted.', ['account' => $gap['account']]) }}
                </p>
            @endforeach
        </div>
    </div>
</section>
