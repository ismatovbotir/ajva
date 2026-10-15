@props(['w'])
@php use App\Monitor\Render; @endphp
@php
    // Text columns (shop, item) read left, figure columns right; decided on the first row.
    $numeric = fn ($v) => (bool) preg_match('/^[▲▼+\-−\s]*[0-9][0-9\s.,]*(%|\s?\p{L}{0,5})?$/u', trim((string) $v));
    $right = [];
    foreach ($w['columns'] as $ci => $column) {
        $right[$ci] = collect($w['items'])->contains(fn ($item) => $numeric($item['cells'][$ci] ?? ''));
    }
@endphp
<div class="h-full min-h-0 overflow-hidden">
    <table class="w-full text-left text-[0.9rem]">
        <thead>
            <tr class="text-[0.75rem] uppercase tracking-wide text-slate-500">
                <th class="py-[0.15rem] pr-2 font-medium"></th>
                @foreach($w['columns'] as $column)
                    <th class="px-1 py-[0.15rem] align-bottom font-medium {{ $right[$loop->index] ? 'text-right' : 'text-left' }}">{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
            @foreach($w['items'] as $item)
                <tr>
                    <td class="w-full max-w-0 truncate py-[0.2rem] pr-2 text-slate-200" title="{{ $item['label'] }}">
                        <span class="mr-[0.5rem] inline-block h-[0.55rem] w-[0.55rem] rounded-full {{ Render::fill($item['status']) }}"></span>{{ $item['label'] }}
                    </td>
                    @foreach($item['cells'] as $ci => $cell)
                        <td class="tabular whitespace-nowrap px-1 py-[0.2rem] {{ $right[$ci] ? 'text-right' : 'text-left' }} {{ ($item['tones'][$ci] ?? null) ? 'font-semibold '.Render::text($item['tones'][$ci]) : 'text-white' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
