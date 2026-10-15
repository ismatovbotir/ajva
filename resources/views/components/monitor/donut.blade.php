{{-- Donut of at most 6 slices + Other (parts) or of up to 7 shops (a longer shop list falls back to bars). --}}
@props(['w'])
@php
    $parts = \App\Monitor\Render::parts($w);
    $total = array_sum(array_column($parts, 'value')) ?: 1.0;
    $offset = 0.0;
@endphp
<div class="flex h-full min-h-0 items-center gap-[0.8rem]">
    <svg viewBox="0 0 42 42" class="aspect-square h-full max-h-[12rem] min-h-0 max-w-[45%] shrink-0" role="img" aria-label="{{ $w['title'] }}">
        <circle cx="21" cy="21" r="15.9155" fill="none" class="stroke-slate-800" stroke-width="6" />
        @foreach($parts as $part)
            @php $share = $part['value'] / $total * 100; @endphp
            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="{{ $part['color'] }}" stroke-width="6"
                    stroke-dasharray="{{ round($share, 3) }} {{ round(100 - $share, 3) }}" stroke-dashoffset="{{ round(25 - $offset, 3) }}" />
            @php $offset += $share; @endphp
        @endforeach
    </svg>
    <ul class="min-w-0 flex-1 space-y-[0.15rem] text-[0.85rem]">
        @foreach($parts as $part)
            <li class="flex items-center justify-between gap-2">
                <span class="inline-flex min-w-0 items-center gap-1 text-slate-300"><span class="inline-block h-[0.6rem] w-[0.6rem] shrink-0 rounded-sm" style="background: {{ $part['color'] }}"></span><span class="truncate">{{ $part['label'] }}</span></span>
                <span class="tabular shrink-0 text-white">{{ number_format($part['value'] / $total * 100, 1) }}%</span>
            </li>
        @endforeach
    </ul>
</div>
