{{-- The widget grid of a custom monitor: CSS grid on md+ (columns/rows from x, y, w, h), one column in reading
     order (y, then x) below. $widgets = MonitorData::build()['widgets']. --}}
@props(['widgets', 'rows' => null])
@php $rows = max(1, (int) $rows, (int) collect($widgets)->map(fn ($w) => $w['y'] + $w['h'])->max()); @endphp
<div {{ $attributes->merge(['class' => 'mon-grid']) }} style="--rows: {{ $rows }}" data-rows="{{ $rows }}">
    @foreach($widgets as $widget)
        <x-monitor.widget :w="$widget" wire:key="mon-w-{{ $widget['id'] }}" />
    @endforeach
    {{ $slot }}
    @if(count($widgets) === 0)
        <div class="mon-empty col-span-full rounded-2xl border border-dashed border-slate-700 p-[2rem] text-center text-[1.1rem] text-slate-400">
            {{ __('This monitor has no widgets yet.') }}
        </div>
    @endif
</div>
