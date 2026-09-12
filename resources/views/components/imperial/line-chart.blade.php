{{--
    Monthly burn line chart: solid through completed months, dashed
    into the current-month estimate, labeled points, month labels.
    Pure presentation: geometry only — the values come from the
    projection via SpendHistory and are never recomputed here.
--}}
@php
    $width = 640;
    $height = 240;
    $padTop = 24;
    $padRight = 16;
    $padBottom = 30;
    $padLeft = 48;

    $plotW = $width - $padLeft - $padRight;
    $plotH = $height - $padTop - $padBottom;

    $maxMinor = 1;
    foreach ($points as $point) {
        $maxMinor = max($maxMinor, $point['minor']);
    }

    // A rounded chart top one nice step above the largest value.
    $step = collect([500, 1000, 2000, 2500, 5000, 10000, 20000, 25000, 50000, 100000, 200000, 500000, 1000000])
        ->first(fn ($candidate) => $candidate >= $maxMinor / 4) ?? 2000000;
    $topMinor = (int) (ceil($maxMinor / $step) * $step);

    $count = max(count($points), 1);
    $chartX = fn (int $index): int => $padLeft + (int) round($index * $plotW / max($count - 1, 1));
    $chartY = fn (int $minor): int => $padTop + (int) round($plotH * (1 - $minor / $topMinor));

    $lastSolid = $count - 1;
    $hasEstimate = ($points[$count - 1]['estimated'] ?? false) === true;
    if ($hasEstimate) {
        $lastSolid--;
    }

    $solidPoints = [];
    foreach (range(0, $lastSolid) as $index) {
        $solidPoints[] = $chartX($index).','.$chartY($points[$index]['minor']);
    }

    $money = fn (int $minor, string $currency): string => \App\Domain\Support\ValueObjects\Money::ofMinor($minor, $currency)->majorAmount();
@endphp

<figure class="w-full" role="img" aria-label="{{ __('Monthly burn line chart') }}">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full">
        @for ($tick = 0; $tick <= $topMinor; $tick += $step)
            <line
                x1="{{ $padLeft }}" x2="{{ $width - $padRight }}"
                y1="{{ $chartY($tick) }}" y2="{{ $chartY($tick) }}"
                class="stroke-line" stroke-width="1"
            />
            <text
                x="{{ $padLeft - 8 }}" y="{{ $chartY($tick) + 4 }}"
                text-anchor="end"
                class="fill-ink-muted font-mono text-[11px]"
            >{{ $money($tick, $points[0]['currency']) }}</text>
        @endfor

        {{-- Solid line: completed months. --}}
        @if (count($solidPoints) >= 2)
            <polyline
                points="{{ implode(' ', $solidPoints) }}"
                fill="none"
                class="stroke-info"
                stroke-width="2"
            />
        @endif

        {{-- Dashed segment: the current month is an estimate. --}}
        @if ($hasEstimate)
            <line
                x1="{{ $chartX(max($lastSolid, 0)) }}"
                y1="{{ $chartY($points[max($lastSolid, 0)]['minor']) }}"
                x2="{{ $chartX($lastSolid + 1) }}"
                y2="{{ $chartY($points[$lastSolid + 1]['minor']) }}"
                class="stroke-info" stroke-width="2" stroke-dasharray="6 5"
            />
        @endif

        @foreach ($points as $index => $point)
            <circle
                cx="{{ $chartX($index) }}" cy="{{ $chartY($point['minor']) }}" r="4"
                class="{{ $point['estimated'] ? 'fill-surface stroke-info' : 'fill-info stroke-surface' }}"
                stroke-width="2"
            />
            <text
                x="{{ $chartX($index) }}" y="{{ $chartY($point['minor']) - 10 }}"
                text-anchor="middle"
                class="fill-ink-secondary font-mono text-[11px]"
            >{{ $money($point['minor'], $point['currency']) }}</text>
            <text
                x="{{ $chartX($index) }}" y="{{ $height - 8 }}"
                text-anchor="middle"
                class="fill-ink-muted text-[11px]"
            >{{ $point['label'] }}</text>
        @endforeach
    </svg>
</figure>
