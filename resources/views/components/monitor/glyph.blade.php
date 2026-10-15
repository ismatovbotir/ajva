{{-- Schematic of a diagram type for the builder canvas (no data): the shape the widget will take on the screen. --}}
@props(['d' => 'kpi'])
<svg viewBox="0 0 120 60" preserveAspectRatio="xMidYMid meet" {{ $attributes->merge(['class' => 'text-brand-700']) }} fill="none" stroke="currentColor" aria-hidden="true">
    @switch($d)
        @case('kpi')
            <rect x="8" y="12" width="56" height="16" rx="3" fill="currentColor" stroke="none" opacity=".75" />
            <rect x="8" y="38" width="30" height="8" rx="3" fill="currentColor" stroke="none" opacity=".3" />
            <rect x="44" y="38" width="30" height="8" rx="3" fill="currentColor" stroke="none" opacity=".3" />
            @break
        @case('sparkline')
            <rect x="8" y="6" width="44" height="12" rx="3" fill="currentColor" stroke="none" opacity=".75" />
            <polyline points="8,48 28,38 46,44 66,28 86,34 112,20" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".6" />
            @break
        @case('line')
            <path d="M8 6v46h104" stroke-width="1.5" opacity=".35" />
            <polyline points="12,40 32,30 50,36 70,16 92,26 112,12" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".75" />
            <polyline points="12,44 32,38 50,40 70,30 92,34 112,28" stroke-width="2" stroke-dasharray="4 3" opacity=".4" />
            @break
        @case('bar')
            <path d="M8 52h104" stroke-width="1.5" opacity=".35" />
            @foreach([20, 34, 26, 44, 38, 30, 14] as $i => $h)
                <rect x="{{ 12 + $i * 14 }}" y="{{ 52 - $h }}" width="9" height="{{ $h }}" rx="1.5" fill="currentColor" stroke="none" opacity=".65" />
            @endforeach
            @break
        @case('stacked_bar')
            <rect x="8" y="14" width="104" height="14" rx="7" fill="currentColor" stroke="none" opacity=".2" />
            <rect x="8" y="14" width="44" height="14" rx="7" fill="currentColor" stroke="none" opacity=".75" />
            <rect x="52" y="14" width="30" height="14" fill="currentColor" stroke="none" opacity=".5" />
            <rect x="8" y="38" width="22" height="5" rx="2" fill="currentColor" stroke="none" opacity=".3" />
            <rect x="40" y="38" width="22" height="5" rx="2" fill="currentColor" stroke="none" opacity=".3" />
            <rect x="72" y="38" width="22" height="5" rx="2" fill="currentColor" stroke="none" opacity=".3" />
            @break
        @case('donut')
            <circle cx="36" cy="30" r="20" stroke-width="9" opacity=".2" />
            <circle cx="36" cy="30" r="20" stroke-width="9" stroke-dasharray="52 74" transform="rotate(-90 36 30)" opacity=".75" />
            <rect x="70" y="16" width="38" height="6" rx="3" fill="currentColor" stroke="none" opacity=".4" />
            <rect x="70" y="28" width="30" height="6" rx="3" fill="currentColor" stroke="none" opacity=".3" />
            <rect x="70" y="40" width="24" height="6" rx="3" fill="currentColor" stroke="none" opacity=".2" />
            @break
        @case('table')
            <rect x="8" y="8" width="104" height="6" rx="2" fill="currentColor" stroke="none" opacity=".5" />
            @foreach([0, 1, 2, 3] as $i)
                <rect x="8" y="{{ 20 + $i * 9 }}" width="40" height="4" rx="2" fill="currentColor" stroke="none" opacity=".3" />
                <rect x="70" y="{{ 20 + $i * 9 }}" width="14" height="4" rx="2" fill="currentColor" stroke="none" opacity=".3" />
                <rect x="94" y="{{ 20 + $i * 9 }}" width="18" height="4" rx="2" fill="currentColor" stroke="none" opacity=".3" />
            @endforeach
            @break
        @case('heatmap')
            @foreach([0, 1, 2, 3] as $r)
                @foreach([0, 1, 2, 3, 4] as $c)
                    <rect x="{{ 10 + $c * 21 }}" y="{{ 8 + $r * 12 }}" width="19" height="10" rx="2" fill="currentColor" stroke="none" opacity="{{ 0.15 + (($r * 3 + $c * 2) % 5) * 0.14 }}" />
                @endforeach
            @endforeach
            @break
        @case('gauge')
            <path d="M16 50a44 44 0 0 1 88 0" stroke-width="10" opacity=".2" />
            <path d="M16 50a44 44 0 0 1 62-40" stroke-width="10" opacity=".75" />
            <rect x="44" y="40" width="32" height="10" rx="3" fill="currentColor" stroke="none" opacity=".5" />
            @break
        @case('ticker')
            @foreach([0, 1, 2, 3, 4] as $i)
                <circle cx="12" cy="{{ 10 + $i * 10 }}" r="2.5" fill="currentColor" stroke="none" opacity=".5" />
                <rect x="22" y="{{ 8 + $i * 10 }}" width="{{ 52 - ($i % 3) * 8 }}" height="4" rx="2" fill="currentColor" stroke="none" opacity=".3" />
                <rect x="88" y="{{ 8 + $i * 10 }}" width="22" height="4" rx="2" fill="currentColor" stroke="none" opacity=".5" />
            @endforeach
            @break
    @endswitch
</svg>
