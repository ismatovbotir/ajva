@props(['label', 'value', 'href' => null, 'asOf' => null, 'tone' => 'default'])
@php
    $valueClass = $tone === 'bad' ? 'text-red-700' : ($tone === 'warn' ? 'text-amber-700' : 'text-slate-900');
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'block rounded-lg border border-slate-200 bg-white p-4'.($href ? ' hover:border-primary-300 hover:bg-primary-50/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500' : '')]) }}>
    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
    <p class="mt-1.5 text-2xl font-semibold tabular-nums {{ $valueClass }}">{{ $value }}</p>
    @if (trim((string) $slot) !== '')
        <div class="mt-2 flex flex-wrap gap-1.5">{{ $slot }}</div>
    @endif
    @if ($asOf)
        <p class="mt-2 text-[11px] text-slate-400">{{ __('as of :time', ['time' => $asOf]) }}</p>
    @endif
</{{ $tag }}>
