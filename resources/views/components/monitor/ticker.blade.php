{{-- Newest receipts. Shop and till only, never a cashier name (and never on a public link: the widget is not public safe). --}}
@props(['w'])
<ul class="mon-list h-full min-h-0 text-[0.9rem]">
    @foreach($w['events'] as $e)
        <li class="flex items-baseline justify-between gap-2 border-b border-slate-800 py-[0.2rem]">
            <span class="min-w-0 truncate text-slate-300">
                <span class="tabular text-slate-500">{{ $e['time'] }}</span>
                {{ $e['shop'] }}@if($e['till']) · {{ $e['till'] }}@endif
                @if($e['tag'] === 'refund')<span class="ml-1 rounded bg-amber-500/15 px-1 text-[0.75rem] font-semibold text-amber-300">{{ __('Refund') }}</span>@endif
                @if($e['tag'] === 'cancelled')<span class="ml-1 rounded bg-red-500/15 px-1 text-[0.75rem] font-semibold text-red-300">{{ __('Cancelled') }}</span>@endif
            </span>
            <span class="tabular shrink-0 font-semibold text-white">{{ $e['total'] }}</span>
        </li>
    @endforeach
</ul>
