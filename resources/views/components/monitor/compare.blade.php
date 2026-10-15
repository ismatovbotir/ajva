{{-- The two baselines of a figure, each cut at the same time of day: one delta chip per baseline (signed, with the arrow
     in the text, so colour is never the only cue) plus, when the tile is tall enough, the baseline value. --}}
@props(['c'])
@php use App\Monitor\Render; @endphp
<div class="mon-compare space-y-[0.2rem] text-[0.8rem] leading-tight tabular text-slate-400">
    @foreach([['prev_label', 'prev_text', 'a_text', 'a_status', 'vs yesterday'], ['lw_label', 'lw_text', 'b_text', 'b_status', 'vs last week']] as [$label, $text, $delta, $status, $short])
        <p class="flex items-center gap-[0.5rem]" title="{{ $c[$label] }}: {{ $c[$text] }}">
            <span class="inline-block min-w-[5.2rem] shrink-0 rounded-md px-[0.4rem] py-[0.05rem] text-center text-[0.85rem] font-bold {{ Render::chip($c[$status]) }}">{{ $c[$delta] }}</span>
            <span class="min-w-0 truncate"><span class="text-slate-500">{{ __($short) }}</span><span class="mon-kpi-detail"> · {{ $c[$text] }}</span></span>
        </p>
    @endforeach
</div>
