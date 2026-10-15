{{-- One widget tile of a custom monitor: title, freshness chip, the diagram, then footer lines.
     $w = a payload from App\Monitor\MonitorData. Dark utility classes only: the public light theme remaps them. --}}
@props(['w'])
@php
    use App\Monitor\Render;

    $state = $w['state'] ?? 'ok';
    $diagram = $state === 'ok' ? Render::diagram($w) : null;
    $style = '--gc: '.($w['x'] + 1).' / span '.$w['w'].'; --gr: '.($w['y'] + 1).' / span '.$w['h'].';';
@endphp

@if($state === 'removed')
    <article class="mon-widget flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-700 p-[0.9rem] text-center" style="{{ $style }}" data-widget="removed">
        <p class="text-[1rem] font-semibold text-slate-400">{{ __('Widget removed') }}</p>
        <p class="text-[0.85rem] text-slate-500">{{ __('This metric no longer exists. Open the builder to replace it.') }}</p>
    </article>
@else
    <article class="mon-widget flex min-h-0 flex-col gap-[0.4rem] rounded-2xl border p-[0.9rem] {{ Render::tile($w['status'] ?? 'none') }}"
             style="{{ $style }}" data-widget="{{ $w['key'] }}" data-diagram="{{ $diagram ?? $w['diagram'] }}">
        <header class="flex items-start justify-between gap-2">
            <h3 class="min-w-0 truncate text-[1rem] font-semibold uppercase tracking-wide text-slate-300" title="{{ $w['title'] }}">{{ $w['title'] }}</h3>
            <div class="flex shrink-0 items-center gap-1 text-[0.75rem]">
                @if($w['incomplete'])
                    <span class="rounded bg-amber-500/15 px-[0.4rem] py-[0.1rem] font-medium text-amber-300" title="{{ __('The same weekday last week has no data.') }}">{{ __('incomplete') }}</span>
                @endif
                <span class="tabular inline-flex items-center gap-1 rounded px-[0.4rem] py-[0.1rem] {{ $w['fresh']['stale'] ? 'bg-red-500/15 text-red-300' : 'text-slate-500' }}"
                      title="{{ __('Updated') }} {{ $w['fresh']['time'] }}">
                    <span class="h-[0.45rem] w-[0.45rem] rounded-full {{ $w['fresh']['stale'] ? 'bg-red-400' : 'bg-emerald-400' }}"></span>{{ substr($w['fresh']['time'], 0, 5) }}
                </span>
            </div>
        </header>

        <div class="min-h-0 flex-1">
            @if($state === 'empty')
                <div class="flex h-full items-center justify-center text-[1rem] text-slate-500">{{ __('No data yet') }}</div>
            @else
                <x-dynamic-component :component="'monitor.'.str_replace('_', '-', $diagram)" :w="$w" />
            @endif
        </div>

        @if($w['caveat'] && $state === 'ok')
            <p class="text-[0.75rem] leading-snug text-amber-300">{{ $w['caveat'] }}</p>
        @endif
        @if($w['rule'] || ! empty($w['footer']))
            <p class="truncate text-[0.75rem] text-slate-500" title="{{ trim(($w['rule'] ?? '').' '.($w['footer'] ?? '')) }}">{{ $w['footer'] ?? '' }}@if($w['rule'] && empty($w['footer'])) {{ $w['rule'] }}@endif</p>
        @endif
    </article>
@endif
