{{-- Bars: vertical grouped bars for a series (labels + lines), horizontal ranked bars for a list (items). --}}
@props(['w'])
@php use App\Monitor\Render; @endphp
@if(isset($w['lines']))
    @php
        $max = 0.0;
        foreach ($w['lines'] as $line) {
            foreach ($line['values'] as $v) {
                $max = max($max, (float) $v);
            }
        }
        $max = Render::niceMax($max);
    @endphp
    <div class="flex h-full min-h-0 flex-col gap-[0.3rem]">
        <div class="flex flex-wrap items-center justify-end gap-x-[0.8rem] text-[0.8rem] text-slate-400">
            @foreach($w['lines'] as $line)
                <span class="inline-flex items-center gap-1"><span class="inline-block h-[0.6rem] w-[0.6rem] rounded-sm" style="background: {{ Render::tone($line['tone']) }}"></span>{{ $line['name'] }}</span>
            @endforeach
        </div>
        <div class="relative flex min-h-0 flex-1 items-end gap-[0.25rem] border-b border-slate-700">
            <span class="pointer-events-none absolute left-0 top-0 text-[0.7rem] leading-none tabular text-slate-500" aria-hidden="true">{{ Render::compact($max) }}</span>
            <span class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-slate-800" aria-hidden="true"></span>
            @foreach($w['labels'] as $i => $label)
                <div class="flex h-full min-w-0 flex-1 items-end justify-center gap-[1px]">
                    @foreach($w['lines'] as $line)
                        @php $v = $line['values'][$i]; @endphp
                        <div class="monitor-bar w-full max-w-[1.4rem] rounded-t-sm" style="height: {{ $v === null ? 0 : max(1, round((float) $v / $max * 100, 1)) }}%; background: {{ Render::tone($line['tone']) }}; opacity: {{ ($w['highlight'] ?? null) && ! in_array($i, $w['highlight'], true) ? '.55' : '1' }}"></div>
                    @endforeach
                </div>
            @endforeach
        </div>
        <div class="flex gap-[0.25rem] text-[0.7rem] tabular text-slate-500">
            @foreach($w['labels'] as $label)
                <span class="min-w-0 flex-1 truncate text-center">{{ $label }}</span>
            @endforeach
        </div>
    </div>
@else
    @php $max = max(1.0, ...array_map(fn ($i) => (float) $i['value'], $w['items'])); @endphp
    <ul class="flex h-full min-h-0 flex-col justify-around gap-[0.25rem] overflow-hidden">
        @foreach($w['items'] as $item)
            <li class="min-w-0">
                <div class="flex items-baseline justify-between gap-2 text-[0.9rem]">
                    <span class="min-w-0 truncate text-slate-200" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                    <span class="tabular shrink-0 font-semibold {{ Render::text($item['status'], 'text-white') }}">{{ $item['text'] }}</span>
                </div>
                <div class="mt-[0.1rem] h-[0.45rem] overflow-hidden rounded-full bg-slate-800">
                    <div class="monitor-bar h-full rounded-full {{ Render::fill($item['status']) }}" style="width: {{ max(2, round(($w['inverse'] ?? false ? 1 - min(1, $item['value'] / 3) : $item['value'] / $max) * 100, 1)) }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
