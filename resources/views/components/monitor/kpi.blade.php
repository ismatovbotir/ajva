@props(['w'])
@php use App\Monitor\Render; @endphp
<div class="flex h-full flex-col justify-between gap-[0.3rem]">
    <div>
        <p class="tabular truncate text-[2.6rem] font-bold leading-none {{ in_array($w['status'], ['bad', 'warn'], true) ? Render::text($w['status']) : 'text-white' }}">{{ $w['value'] ?? number_format((float) collect($w['lines'][0]['values'] ?? [])->filter(fn ($v) => $v !== null)->last(), 0, '.', ' ') }}</p>
        @if(! empty($w['note']))
            <p class="mt-[0.2rem] text-[0.85rem] text-slate-400">{{ $w['note'] }}</p>
        @endif
    </div>
    @if(isset($w['compare']))
        <x-monitor.compare :c="$w['compare']" />
    @endif
</div>
