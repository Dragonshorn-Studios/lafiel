{{--
    Imperial Ledger status badge for a synchronization run. One place
    decides how every sync status reads, so the providers page and the
    activity page (and any future surface) never disagree
    (docs/design-system.md: pills only for status; color carries
    meaning, never the provider).
--}}
@props([
    'status' => null,
])

@php
    $variant = match ($status?->value) {
        'succeeded' => 'success',
        'partial' => 'warning',
        'failed' => 'danger',
        'running' => 'info',
        default => 'neutral',
    };
@endphp

<flux:badge :variant="$variant" size="sm" {{ $attributes }} data-test="sync-status-badge">
    @if ($status?->value === 'running')
        <flux:icon.arrow-path variant="mini" class="size-3.5 animate-spin" />
    @elseif ($status?->value === 'queued')
        <flux:icon.clock variant="mini" class="size-3.5" />
    @endif
    {{ $status?->label() ?? __('unknown') }}
</flux:badge>
