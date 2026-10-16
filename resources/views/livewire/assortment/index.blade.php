@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $num = fn ($n, $d = 1) => number_format((float) $n, $d, '.', ' ');
    $pct = fn ($n) => number_format((float) $n, 1, '.', ' ').' %';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    // What to do with each cell of the matrix (A = most revenue, X = steadiest demand).
    $actions = [
        'A' => ['X' => __('Never out of stock: tight min/max, replenish first.'), 'Y' => __('Keep a safety stock, watch promotions.'), 'Z' => __('Keep it, find why demand jumps, do not cut.')],
        'B' => ['X' => __('Automatic min/max.'), 'Y' => __('Standard rules.'), 'Z' => __('Review the rule, order carefully.')],
        'C' => ['X' => __('Low priority, min/max only.'), 'Y' => __('Order in batches.'), 'Z' => __('Delist or order only on demand.')],
    ];
    $tone = fn ($a, $x) => match (true) {
        $a === 'A' && $x === 'X' => 'bg-emerald-50 border-emerald-200',
        $a === 'C' && $x === 'Z' => 'bg-amber-50 border-amber-200',
        $a === 'A' => 'bg-brand-50 border-brand-100',
        default => 'bg-white border-slate-200',
    };
    $levelClass = ['warn' => 'border-amber-300 bg-amber-50 text-amber-900', 'info' => 'border-slate-200 bg-slate-50 text-slate-800'];
@endphp
<div>
    <x-ui.page-header :title="__('Assortment analysis')" :subtitle="__('Which items carry the business, which can go, and where sales or cash are lost.')" />

    <form wire:submit="generate" class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-44">
            <x-ui.label for="dateFrom">{{ __('From') }}</x-ui.label>
            <input type="date" id="dateFrom" wire:model="dateFrom" max="{{ now()->toDateString() }}"
                   class="block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
            <x-ui.error name="dateFrom" />
        </div>
        <div class="w-full sm:w-44">
            <x-ui.label for="dateTo">{{ __('To') }}</x-ui.label>
            <input type="date" id="dateTo" wire:model="dateTo" max="{{ now()->toDateString() }}"
                   class="block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
            <x-ui.error name="dateTo" />
        </div>

        <div class="relative w-full sm:w-72" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <x-ui.label>{{ __('Shops') }}</x-ui.label>
            <button type="button" @click="open = !open"
                    class="flex w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                <span class="truncate">{{ count($shopIds) === 0 ? __('All shops') : __(':count shops selected', ['count' => count($shopIds)]) }}</span>
                <span class="text-[10px] text-slate-400">▼</span>
            </button>
            <div x-show="open" x-cloak class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                @foreach($shops as $shop)
                    <label wire:key="shop-{{ $shop->id }}" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                        <input type="checkbox" wire:model="shopIds" value="{{ $shop->id }}" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />
                        <span class="truncate">{{ $shop->name }}</span>
                    </label>
                @endforeach
                <button type="button" wire:click="$set('shopIds', [])" class="mt-1 w-full rounded px-2 py-1.5 text-left text-xs text-slate-500 hover:bg-slate-50">{{ __('Clear selection (all shops)') }}</button>
            </div>
        </div>

        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="generate">
            <span wire:loading.remove wire:target="generate">{{ __('Generate') }}</span>
            <span wire:loading wire:target="generate" style="display: none;">{{ __('Generating report') }}…</span>
        </x-ui.button>
    </form>
    <p class="mb-6 text-xs text-slate-500">{{ __('Recommended period: the last 12 weeks. ABC needs about 8 to 13 weeks to smooth pay days and short promotions; XYZ uses weekly sales and needs at least 8 full weeks. Only whole weeks are used.') }}</p>

    @if($noShops)
        <x-ui.no-shops />
    @elseif(! $generated)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick the period and shops, then press Generate.')" />
    @elseif(($report['kpi']['items'] ?? 0) === 0)
        <x-ui.empty-state :title="__('No sales')" :description="__('There are no sales for this period and shops.')" />
    @else
        @php($k = $report['kpi'])
        @php($w = $report['window'])

        <p class="mb-3 text-xs text-slate-500">
            {{ __('Period used: :from to :to (:weeks full weeks, :days days). Successful sales only, storno lines excluded, refunds not netted.', ['from' => $w['from'], 'to' => $w['to'], 'weeks' => $w['weeks'], 'days' => $w['days']]) }}
            @if($k['stock_as_of']) {{ __('Stock as of :date.', ['date' => $k['stock_as_of']]) }} @endif
        </p>

        {{-- KPI strip --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach([
                [__('Items sold'), $money($k['items']), __('Revenue :n', ['n' => $money($k['revenue'])])],
                [__('Class A items'), $money($k['a_items']), $pct($k['a_items_share']).' '.__('of items, 80 % of sales')],
                [__('A items out of stock'), $money($k['a_at_risk']), __('in at least one shop now')],
                [__('Dead stock'), $money($k['dead']), __(':n slow (over :d days of cover)', ['n' => $k['slow'], 'd' => $k['slow_days']])],
            ] as $i => [$label, $value, $hint])
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums {{ ($i === 2 && $k['a_at_risk'] > 0) ? 'text-red-700' : (($i === 3 && $k['dead'] > 0) ? 'text-amber-700' : 'text-slate-900') }}">{{ $value }}@if(($i === 2 && $k['a_at_risk'] > 0) || ($i === 3 && $k['dead'] > 0)) <span class="sr-only">{{ __('needs attention') }}</span><span aria-hidden="true" class="text-base">&#9888;</span>@endif</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Conclusions --}}
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Conclusions') }}</h2>
        <ul class="mb-6 space-y-2">
            @foreach($notes as $note)
                <li class="rounded-lg border px-4 py-2.5 text-sm {{ $levelClass[$note['level']] ?? $levelClass['info'] }}">
                    <span class="font-semibold">{{ $note['level'] === 'warn' ? __('Action') : __('Note') }}:</span> {{ $note['text'] }}
                </li>
            @endforeach
        </ul>

        {{-- ABC x XYZ --}}
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('ABC x XYZ matrix') }}</h2>
        @if($report['matrix'])
            <x-ui.card padding="p-3 sm:p-4" class="mb-2">
                <div class="overflow-x-auto">
                    <div class="grid min-w-[40rem] grid-cols-[5rem_repeat(3,minmax(0,1fr))] gap-2 text-sm">
                        <div></div>
                        @foreach(['X' => __('X: steady demand'), 'Y' => __('Y: some swings'), 'Z' => __('Z: erratic')] as $x => $label)
                            <div class="px-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                        @endforeach
                        @foreach(['A' => __('A: top sales'), 'B' => __('B: middle'), 'C' => __('C: tail')] as $a => $alabel)
                            <div class="flex items-center text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $alabel }}</div>
                            @foreach(['X', 'Y', 'Z'] as $x)
                                @php($cell = $report['matrix'][$a][$x])
                                <div class="rounded-lg border p-3 {{ $tone($a, $x) }}">
                                    <p class="flex items-baseline justify-between gap-2"><span class="text-xs font-bold text-slate-600">{{ $a }}{{ $x }}</span><span class="text-lg font-semibold tabular-nums text-slate-900">{{ $money($cell['items']) }}</span></p>
                                    <p class="text-xs tabular-nums text-slate-600">{{ $pct($cell['share']) }} {{ __('of sales') }}</p>
                                    <p class="mt-1 text-xs text-slate-700">{{ $actions[$a][$x] }}</p>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            </x-ui.card>
            <p class="mb-6 text-xs text-slate-500">{{ __('Items per cell, share of revenue, and what to do. XYZ = variation of weekly sales: X up to 0.25, Y up to 0.5, Z above. An item launched inside the period reads as Z.') }}</p>
        @else
            <x-ui.card class="mb-6">
                <p class="text-sm text-slate-700">{{ __('XYZ needs at least :w full weeks, this period has :n. Choose a longer period to see the matrix.', ['w' => \App\Services\AssortmentAnalysis::MIN_WEEKS_XYZ, 'n' => $w['weeks']]) }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ __('ABC only') }}: @foreach($report['abc_summary'] as $class => $c) <span class="mr-3"><b>{{ $class }}</b> {{ $money($c['items']) }} {{ __('items') }} ({{ $pct($c['share']) }})</span>@endforeach</p>
            </x-ui.card>
        @endif

        {{-- Item tables --}}
        @if($report['at_risk'])
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Class A items out of stock') }}</h2>
            <x-ui.card padding="p-0" class="mb-6">
                <div class="max-h-96 overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr><th class="{{ $th }} text-left">{{ __('Item') }}</th><th class="{{ $th }} text-right">{{ __('Share of sales') }}</th><th class="{{ $th }} text-right">{{ __('Sales per day') }}</th><th class="{{ $th }} text-right">{{ __('Shops out') }}</th><th class="{{ $th }} text-right">{{ __('On hand') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['at_risk'] as $r)
                        <tr wire:key="risk-{{ $r['id'] }}"><td class="px-3 py-2">{{ $r['name'] }} <span class="rounded bg-brand-100 px-1.5 text-xs font-semibold text-brand-800">{{ $r['abc'] }}{{ $r['xyz'] }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $pct($r['share']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $money($r['rev'] / $w['days']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold text-red-700">{{ $r['out_shops'] }} / {{ $r['shops'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['on_hand'], 0) }}</td></tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
        @endif

        @if($report['dead'])
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Dead stock: stock but no sales') }}</h2>
            <x-ui.card padding="p-0" class="mb-6">
                <div class="max-h-96 overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr><th class="{{ $th }} text-left">{{ __('Item') }}</th><th class="{{ $th }} text-right">{{ __('On hand') }}</th>@if($report['profit'])<th class="{{ $th }} text-right">{{ __('Value at cost') }}</th>@endif</tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['dead'] as $r)
                        <tr wire:key="dead-{{ $r['id'] }}"><td class="px-3 py-2">{{ $r['name'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['on_hand'], 0) }}</td>
                            @if($report['profit'])<td class="px-3 py-2 text-right tabular-nums">{{ $r['value'] === null ? __('no cost price') : $money($r['value']) }}</td>@endif</tr>
                    @endforeach
                    </tbody></table></div>
                @if($k['dead'] > count($report['dead']))<p class="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">{{ __('Showing the biggest :n of :total items.', ['n' => count($report['dead']), 'total' => $k['dead']]) }}</p>@endif
            </x-ui.card>
        @endif

        @if($report['slow'])
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Slow movers: more than :d days of cover', ['d' => $k['slow_days']]) }}</h2>
            <x-ui.card padding="p-0" class="mb-6">
                <div class="max-h-96 overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr><th class="{{ $th }} text-left">{{ __('Item') }}</th><th class="{{ $th }} text-right">{{ __('On hand') }}</th><th class="{{ $th }} text-right">{{ __('Sold in the period') }}</th><th class="{{ $th }} text-right">{{ __('Cover, days') }}</th>@if($report['profit'])<th class="{{ $th }} text-right">{{ __('Value at cost') }}</th>@endif</tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['slow'] as $r)
                        <tr wire:key="slow-{{ $r['id'] }}"><td class="px-3 py-2">{{ $r['name'] }} <span class="rounded bg-brand-100 px-1.5 text-xs font-semibold text-brand-800">{{ $r['abc'] }}{{ $r['xyz'] }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['on_hand'], 0) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['qty'], 0) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold text-amber-700">{{ $num($r['cover'], 0) }}</td>
                            @if($report['profit'])<td class="px-3 py-2 text-right tabular-nums">{{ $r['value'] === null ? __('no cost price') : $money($r['value']) }}</td>@endif</tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
        @endif

        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Top items with their class') }}</h2>
        <x-ui.card padding="p-0" class="mb-6">
            <div class="max-h-96 overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                <thead><tr><th class="{{ $th }} text-left">{{ __('Item') }}</th><th class="{{ $th }} text-center">{{ __('Class') }}</th><th class="{{ $th }} text-right">{{ __('Revenue') }}</th><th class="{{ $th }} text-right">{{ __('Share') }}</th><th class="{{ $th }} text-right">{{ __('Quantity') }}</th><th class="{{ $th }} text-right">{{ __('On hand') }}</th><th class="{{ $th }} text-right">{{ __('Cover, days') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                @foreach($report['top'] as $r)
                    <tr wire:key="top-{{ $r['id'] }}"><td class="px-3 py-2">{{ $r['name'] }}</td>
                        <td class="px-3 py-2 text-center"><span class="rounded bg-brand-100 px-1.5 text-xs font-semibold text-brand-800">{{ $r['abc'] }}{{ $r['xyz'] }}</span></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $money($r['rev']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $pct($r['share']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['qty'], 0) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r['on_hand'] === null ? '—' : $num($r['on_hand'], 0) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r['cover'] === null ? '—' : $num($r['cover'], 0) }}</td></tr>
                @endforeach
                </tbody></table></div>
        </x-ui.card>
    @endif
</div>
