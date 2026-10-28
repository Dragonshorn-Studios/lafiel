{{--
    Paper frame for page-level Flux tables. First/last cells otherwise
    collapse to ps-0/pe-0, which parks labels and totals on the border —
    especially on Services group rows that span every column.
--}}
<div
    {{ $attributes->merge(['class' => 'overflow-hidden rounded-card border border-line bg-surface [&_[data-flux-cell]:first-child]:ps-4 [&_[data-flux-cell]:last-child]:pe-4 [&_[data-flux-column]:first-child]:ps-4 [&_[data-flux-column]:last-child]:pe-4']) }}
>
    {{ $slot }}
</div>
