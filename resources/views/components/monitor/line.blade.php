{{-- Line chart of one or more series (labels on the x axis). A null value ends the line (hours still to come).
     Fewer than 3 points never reach this component (App\Monitor\Render::diagram falls back to a number). --}}
@props(['w'])
@php
    use App\Monitor\Render;

    $lines = $w['lines'];
    $n = count($w['labels']);
    $max = 0.0;
    foreach ($lines as $line) {
        foreach ($line['values'] as $v) {
            $max = max($max, (float) $v);
        }
    }
    $max = Render::niceMax($max);
    $x = fn (int $i) => number_format($i / ($n - 1) * 100, 2, '.', '');
    $y = fn (float $v) => number_format(38 - $v / $max * 36, 2, '.', '');
    $paths = [];
    foreach ($lines as $li => $line) {
        $segments = [];
        $current = [];
        foreach ($line['values'] as $i => $v) {
            if ($v === null) {
                if ($current) { $segments[] = $current; }
                $current = [];
            } else {
                $current[] = $x($i).','.$y((float) $v);
            }
        }
        if ($current) { $segments[] = $current; }
        $paths[$li] = $segments;
    }
    $step = max(1, (int) ceil($n / 12));
@endphp
<div class="flex h-full min-h-0 flex-col gap-[0.3rem]">
    <div class="flex flex-wrap items-center justify-end gap-x-[0.9rem] text-[0.8rem] text-slate-400">
        @foreach($lines as $line)
            <span class="inline-flex items-center gap-[0.35rem]"><svg viewBox="0 0 18 4" class="h-[0.3rem] w-[1.1rem]"><line x1="0" x2="18" y1="2" y2="2" stroke="{{ Render::tone($line['tone']) }}" stroke-width="3" @if($line['tone'] !== 'now') stroke-dasharray="5 3" @endif /></svg>{{ $line['name'] }}</span>
        @endforeach
    </div>
    <div class="flex min-h-0 flex-1 gap-[0.4rem]">
        <div class="relative w-[2.6rem] shrink-0 text-right text-[0.7rem] tabular text-slate-500" aria-hidden="true">
            @foreach([0.5, 1] as $g)
                <span class="absolute right-0 -translate-y-1/2 leading-none" style="top: {{ (38 - $g * 36) / 40 * 100 }}%">{{ Render::compact($max * $g) }}</span>
            @endforeach
            <span class="absolute right-0 -translate-y-1/2 leading-none" style="top: {{ 38 / 40 * 100 }}%">0</span>
        </div>
        <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="min-h-0 min-w-0 flex-1 overflow-visible" role="img" aria-label="{{ $w['title'] }}">
            @foreach([0.25, 0.5, 0.75, 1] as $g)
                <line x1="0" x2="100" y1="{{ 38 - $g * 36 }}" y2="{{ 38 - $g * 36 }}" class="stroke-slate-800" stroke-width="1" vector-effect="non-scaling-stroke" />
            @endforeach
            <line x1="0" x2="100" y1="38" y2="38" class="stroke-slate-700" stroke-width="1" vector-effect="non-scaling-stroke" />
            @isset($w['cut'])
                <line x1="{{ $x($w['cut']) }}" x2="{{ $x($w['cut']) }}" y1="0" y2="38" class="stroke-slate-600" stroke-width="1" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" />
            @endisset
            @foreach($lines as $li => $line)
                @foreach($paths[$li] as $segment)
                    @if(count($segment) === 1)
                        @php [$px, $py] = explode(',', $segment[0]); @endphp
                        <line x1="{{ $px }}" x2="{{ $px }}" y1="{{ $py }}" y2="{{ $py }}" stroke="{{ Render::tone($line['tone']) }}" stroke-width="6" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    @else
                        <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ Render::tone($line['tone']) }}" stroke-width="{{ $line['tone'] === 'now' ? 3 : 1.75 }}"
                                  @if($line['tone'] !== 'now') stroke-dasharray="5 4" @endif stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    @endif
                @endforeach
            @endforeach
        </svg>
    </div>
    <div class="flex gap-[0.4rem]" aria-hidden="true">
        <span class="w-[2.6rem] shrink-0"></span>
        <div class="relative h-[0.9rem] min-w-0 flex-1 text-[0.7rem] tabular text-slate-500">
            @foreach($w['labels'] as $i => $label)
                @if($i % $step === 0)
                    <span class="absolute top-0 leading-none {{ $n > 6 && $i % 2 === 1 ? 'max-md:hidden' : '' }} {{ $i === 0 ? '' : ($i === $n - 1 ? '-translate-x-full' : '-translate-x-1/2') }}" style="left: {{ $x($i) }}%">{{ $label }}</span>
                @endif
            @endforeach
        </div>
    </div>
</div>
