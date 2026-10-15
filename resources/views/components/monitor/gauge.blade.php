{{-- Half-circle gauge for a rate. The arc turns amber/red with the widget status; marks show the thresholds. --}}
@props(['w'])
@php
    use App\Monitor\Render;

    $g = $w['gauge'];
    $fraction = $g['max'] > $g['min'] ? max(0, min(1, ($g['value'] - $g['min']) / ($g['max'] - $g['min']))) : 0;
    $point = function (float $f, float $r) {
        $a = M_PI * (1 - $f);

        return [round(50 + $r * cos($a), 2), round(50 - $r * sin($a), 2)];
    };
@endphp
<div class="flex h-full flex-col items-center justify-between gap-[0.3rem]">
    <div class="relative w-full max-w-[16rem] min-h-0 flex-1">
        <svg viewBox="0 0 100 56" class="h-full w-full" role="img" aria-label="{{ $w['title'] }}: {{ $w['value'] }}">
            <path d="M10 50 A40 40 0 0 1 90 50" pathLength="100" fill="none" class="stroke-slate-700" stroke-width="9" stroke-linecap="butt" />
            <path d="M10 50 A40 40 0 0 1 90 50" pathLength="100" fill="none" class="{{ Render::stroke($w['status']) }}" stroke-width="9" stroke-linecap="butt"
                  stroke-dasharray="{{ round($fraction * 100, 2) }} 100" />
            @foreach($g['marks'] as $mark)
                @php
                    $f = max(0, min(1, ($mark - $g['min']) / ($g['max'] - $g['min'])));
                    [$x1, $y1] = $point($f, 34);
                    [$x2, $y2] = $point($f, 47);
                @endphp
                <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" class="stroke-slate-200" stroke-width="1.2" />
            @endforeach
        </svg>
        <p class="tabular absolute inset-x-0 bottom-0 text-center text-[1.9rem] font-bold leading-none {{ in_array($w['status'], ['bad', 'warn'], true) ? Render::text($w['status']) : 'text-white' }}">{{ $w['value'] }}</p>
    </div>
    @if(! empty($w['note']))
        <p class="text-center text-[0.85rem] text-slate-400">{{ $w['note'] }}</p>
    @endif
    @if(isset($w['compare']))
        <div class="w-full"><x-monitor.compare :c="$w['compare']" /></div>
    @endif
</div>
