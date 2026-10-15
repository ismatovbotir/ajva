@props(['w'])
@php $parts = \App\Monitor\Render::parts($w); @endphp
<div class="flex h-full flex-col justify-center gap-[0.6rem]">
    <div class="flex h-[1.6rem] w-full overflow-hidden rounded-full bg-slate-800" role="img" aria-label="{{ $w['title'] }}">
        @foreach($parts as $part)
            <div class="monitor-bar h-full" style="width: {{ round($part['value'], 2) }}%; background: {{ $part['color'] }}" title="{{ $part['label'] }} {{ $part['text'] }}"></div>
        @endforeach
    </div>
    <ul class="grid grid-cols-2 gap-x-[0.8rem] gap-y-[0.15rem] text-[0.85rem]">
        @foreach($parts as $part)
            <li class="flex items-center justify-between gap-2">
                <span class="inline-flex min-w-0 items-center gap-1 text-slate-300"><span class="inline-block h-[0.6rem] w-[0.6rem] shrink-0 rounded-sm" style="background: {{ $part['color'] }}"></span><span class="truncate">{{ $part['label'] }}</span></span>
                <span class="tabular shrink-0 text-white">{{ number_format($part['value'], 1) }}%</span>
            </li>
        @endforeach
    </ul>
</div>
