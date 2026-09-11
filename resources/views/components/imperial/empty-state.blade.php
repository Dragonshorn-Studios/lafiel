{{--
    Imperial Ledger empty state for tables and lists. Incompleteness is
    a feature: say plainly that nothing is here, never fake rows.
--}}
@props([
    'hint' => null,
])
<div
    {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 rounded-card border border-dashed border-line bg-surface-subtle/60 px-6 py-12 text-center']) }}
    data-test="empty-state"
>
    <flux:icon.inbox variant="outline" class="size-8 text-ink-muted" />
    <flux:heading>{{ __('Nothing here') }}</flux:heading>

    @isset($hint)
        <p class="max-w-sm text-sm text-ink-secondary">{{ $hint }}</p>
    @endisset

    {{ $slot }}
</div>
