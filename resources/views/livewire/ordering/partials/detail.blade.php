{{-- Forecast detail of one line: stock position falling against the cumulative forecast, and expected sales per day.
     Inputs: $detail (see Ordering\Index::charts), $horizon, $fmt, $fmt1. Pure SVG, values are labelled directly. --}}
@php
    $W = 320; $H = 150; $L = 34; $R = 12; $T = 14; $B = 24;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $pos = $detail['position'];
    $min = $detail['min'];
    $days = $detail['days'];
    $series = [$pos];
    $series[] = max($pos - $detail['today_rem'], 0);
    foreach ($detail['F'] as $cum) { $series[] = max($pos - $cum, 0); }
    // points: 0 = now, i = end of day i-1 (day 0 = today)
    $yMax = max($pos, $min, 1) * 1.08;
    $x = fn ($i) => $L + $pw * $i / 8;
    $y = fn ($v) => $T + $ph * (1 - min($v, $yMax) / $yMax);
    $pts = collect($series)->map(fn ($v, $i) => round($x($i), 1).','.round($y($v), 1))->implode(' ');
    $so = $detail['stockout_in'];
    $soX = $so === null ? null : $x(min($so + 1, 8));
    $hx = $x(min($horizon + 1, 8));
    $bars = array_merge([$detail['today_rem']], $detail['f']);
    $bMax = max(max($bars), 0.0001) * 1.15;
    $bw = $pw / 8;
@endphp
<div class="grid gap-4 md:grid-cols-2" data-detail>
    @if($detail['rules_only'])
        <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 md:col-span-2">{{ __('No sales history: only the min/max rules apply, there is no forecast chart.') }}</p>
    @else
        <figure class="min-w-0">
            <figcaption class="mb-1 text-xs font-semibold text-slate-600">{{ __('Stock position against forecast sales') }}</figcaption>
            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-auto w-full" role="img"
                 aria-label="{{ __('Stock position against forecast sales') }}: {{ $detail['item'] }}">
                <rect x="{{ $L }}" y="{{ $T }}" width="{{ round($hx - $L, 1) }}" height="{{ $ph }}" fill="#edf5f0"></rect>
                @foreach([0, 0.5, 1] as $g)
                    <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ round($T + $ph * (1 - $g), 1) }}" y2="{{ round($T + $ph * (1 - $g), 1) }}" stroke="#e2e8f0"></line>
                    <text x="{{ $L - 4 }}" y="{{ round($T + $ph * (1 - $g) + 3, 1) }}" text-anchor="end" font-size="9" fill="#475569">{{ $fmt1($yMax * $g) }}</text>
                @endforeach
                @if($min > 0)
                    <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ round($y($min), 1) }}" y2="{{ round($y($min), 1) }}" stroke="#b45309" stroke-width="1.5" stroke-dasharray="5 3"></line>
                    <text x="{{ $W - $R }}" y="{{ round($y($min) - 3, 1) }}" text-anchor="end" font-size="9" fill="#b45309">{{ __('Min') }} {{ $fmt($min) }}</text>
                @endif
                @if($soX !== null)
                    <line x1="{{ round($soX, 1) }}" x2="{{ round($soX, 1) }}" y1="{{ $T }}" y2="{{ $T + $ph }}" stroke="#b91c1c" stroke-width="1.5" stroke-dasharray="2 3"></line>
                    <text x="{{ round(min($soX, $W - $R - 2), 1) }}" y="{{ round($T + $ph * 0.55, 1) }}" stroke="#fff" stroke-width="3" paint-order="stroke" text-anchor="{{ $so >= 6 ? 'end' : 'start' }}" dx="{{ $so >= 6 ? -4 : 4 }}" font-size="9" font-weight="700" fill="#b91c1c">{{ __('Out of stock') }} · {{ $days[min($so, 7)] }}</text>
                @endif
                <polyline points="{{ $pts }}" fill="none" stroke="#2a5039" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"></polyline>
                @foreach($series as $i => $v)
                    <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($v), 1) }}" r="2.5" fill="#2a5039"></circle>
                @endforeach
                <text x="{{ round($x(0) + 4, 1) }}" y="{{ round($y($pos) - 5, 1) }}" font-size="9" font-weight="700" fill="#2a5039">{{ $fmt1($pos) }}</text>
                <text x="{{ $L }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="9" fill="#475569">{{ __('Now') }}</text>
                @for($i = 1; $i <= 8; $i++)
                    <text x="{{ round($x($i), 1) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="9" fill="#475569">{{ $days[$i - 1] }}</text>
                @endfor
            </svg>
            <p class="mt-1 text-xs text-slate-500">{{ __('Shaded: the :days days the order covers. Dashed: minimum.', ['days' => $horizon]) }}</p>
        </figure>

        <figure class="min-w-0">
            <figcaption class="mb-1 text-xs font-semibold text-slate-600">{{ __('Expected sales per day') }}</figcaption>
            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-auto w-full" role="img"
                 aria-label="{{ __('Expected sales per day') }}: {{ $detail['item'] }}">
                <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $T + $ph }}" y2="{{ $T + $ph }}" stroke="#94a3b8"></line>
                @foreach($bars as $i => $v)
                    @php $bh = $ph * $v / $bMax; @endphp
                    <rect x="{{ round($L + $i * $bw + 4, 1) }}" y="{{ round($T + $ph - $bh, 1) }}" width="{{ round($bw - 8, 1) }}" height="{{ round($bh, 1) }}" rx="2"
                          fill="{{ $i === 0 ? '#94a3b8' : ($i <= $horizon ? '#487f5c' : '#cbd5e1') }}"></rect>
                    <text x="{{ round($L + $i * $bw + $bw / 2, 1) }}" y="{{ round($T + $ph - $bh - 3, 1) }}" text-anchor="middle" font-size="9" fill="#334155">{{ $fmt1($v) }}</text>
                    <text x="{{ round($L + $i * $bw + $bw / 2, 1) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="9" fill="#475569">{{ $days[$i] }}</text>
                @endforeach
            </svg>
            <p class="mt-1 text-xs text-slate-500">{{ __('Green: days the order covers. First bar: the rest of today.') }}</p>
        </figure>
    @endif
</div>
