{{-- Shops x metrics. Sequential columns (sales, receipts, average check) shade by share of the maximum;
     diverging columns (vs yesterday / last week) are green above and red below the baseline. --}}
@props(['w'])
@php use App\Monitor\Render; @endphp
<div class="h-full min-h-0 overflow-hidden">
    <table class="h-full max-h-[26rem] w-full border-separate border-spacing-[0.15rem] text-[0.9rem]">
        <thead>
            <tr class="text-[0.75rem] uppercase tracking-wide text-slate-500">
                <th></th>
                @foreach($w['columns'] as $column)
                    <th class="px-1 text-center align-bottom font-medium">{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($w['items'] as $item)
                <tr>
                    <td class="max-w-[9rem] truncate whitespace-nowrap pr-2 text-slate-200" title="{{ $item['label'] }}">{{ $item['label'] }}</td>
                    @foreach($item['cells'] as $ci => $cell)
                        <td class="tabular rounded px-1 py-[0.2rem] text-center text-white" style="{{ Render::heat($item['heat'][$ci] ?? null, $w['kinds'][$ci] ?? 'seq') }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
