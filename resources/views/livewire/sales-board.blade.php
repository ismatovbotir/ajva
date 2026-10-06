@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $deltaBadge = function (?float $d) {
        if ($d === null) {
            return ['—', 'text-slate-400'];
        }

        return [($d >= 0 ? '▲ +' : '▼ ').number_format($d, 1).'%', $d >= 0 ? 'text-emerald-700' : 'text-red-700'];
    };
    [$sumDeltaText, $sumDeltaClass] = $deltaBadge($totals['sum_delta']);
    [$countDeltaText, $countDeltaClass] = $deltaBadge($totals['count_delta']);
    [$avgDeltaText, $avgDeltaClass] = $deltaBadge($totals['avg_delta']);
    [$profitDeltaText, $profitDeltaClass] = $deltaBadge($profit['delta']);
@endphp
<div wire:poll.120s class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="text-lg font-semibold text-[#0b0b0b]">{{ __('Sales today') }}</h2>
            <p class="text-sm text-[#52514e]">{{ __('Successful sales, compared with the previous day up to the same time.') }}</p>
        </div>
        <p class="text-xs text-[#52514e]">{{ __('Updated') }} {{ $asOf }} · {{ __('updates every 2 minutes') }}</p>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Sales total') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-[#0b0b0b]">{{ $money($totals['sum']) }}</p>
            <p class="mt-1 text-sm text-[#52514e]">
                {{ __('Yesterday') }}: <span class="tabular-nums">{{ $money($totals['y_sum']) }}</span>
                <span class="ml-2 font-medium {{ $sumDeltaClass }}">{{ $sumDeltaText }}</span>
            </p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Profit') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums {{ $profit['total'] < 0 ? 'text-red-700' : 'text-[#0b0b0b]' }}">{{ $money($profit['total']) }}</p>
            <p class="mt-1 text-sm text-[#52514e]">
                {{ __('Yesterday') }}: <span class="tabular-nums">{{ $money($profit['yesterday']) }}</span>
                <span class="ml-2 font-medium {{ $profitDeltaClass }}">{{ $profitDeltaText }}</span>
            </p>
            <p class="mt-1 text-sm text-[#52514e]">{{ __('Margin') }}: <span class="font-medium tabular-nums">{{ $profit['margin'] === null ? '—' : number_format($profit['margin'], 1).'%' }}</span></p>
            @if($profit['missing_items'] > 0)
                <p class="mt-2 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800">
                    {{ __('Cost missing for :count items — :percent% of revenue is not covered.', ['count' => $profit['missing_items'], 'percent' => $profit['uncovered_percent']]) }}
                </p>
            @endif
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Receipts') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-[#0b0b0b]">{{ $totals['count'] }}</p>
            <p class="mt-1 text-sm text-[#52514e]">
                {{ __('Yesterday') }}: <span class="tabular-nums">{{ $totals['y_count'] }}</span>
                <span class="ml-2 font-medium {{ $countDeltaClass }}">{{ $countDeltaText }}</span>
            </p>
            @if($refunds['count'] > 0)
                <p class="mt-1 text-sm text-red-700">{{ __('Refunds') }}: <span class="tabular-nums">{{ $refunds['count'] }} · {{ $money($refunds['sum']) }}</span></p>
            @endif
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Average check') }}</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-[#0b0b0b]">{{ $money($totals['avg']) }}</p>
            <p class="mt-1 text-sm text-[#52514e]">
                {{ __('Yesterday') }}: <span class="tabular-nums">{{ $money($totals['y_avg']) }}</span>
                <span class="ml-2 font-medium {{ $avgDeltaClass }}">{{ $avgDeltaText }}</span>
            </p>
        </x-ui.card>
    </div>

    {{-- Payment mix + 7-day trend --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <x-ui.card class="lg:col-span-2">
            <h3 class="text-base font-semibold text-[#0b0b0b]">{{ __('Payment types today') }}</h3>
            <p class="mb-4 text-sm text-[#52514e]">{{ __('Share of successful sales by payment type.') }}</p>
            @if(empty($payments))
                <x-ui.empty-state :title="__('No sales yet today')" :description="__('Sales will appear here as POS terminals send receipts.')" />
            @else
                <div class="mb-4 flex h-4 overflow-hidden rounded-full bg-slate-100">
                    @foreach($payments as $pay)
                        <div title="{{ $pay['name'] }}: {{ $pay['sum'] }}" style="width: {{ $pay['percent'] }}%; background-color: {{ $pay['color'] }};"></div>
                    @endforeach
                </div>
                <ul class="space-y-2">
                    @foreach($payments as $pay)
                        <li class="flex items-center gap-2 text-sm">
                            <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $pay['color'] }}"></span>
                            <span class="min-w-0 flex-1 truncate text-[#0b0b0b]">{{ $pay['name'] }}</span>
                            <span class="tabular-nums text-slate-500">{{ $pay['sum'] }}</span>
                            <span class="w-14 text-right font-medium tabular-nums text-slate-700">{{ number_format($pay['percent'], 1) }}%</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card class="lg:col-span-3">
            <h3 class="text-base font-semibold text-[#0b0b0b]">{{ __('Last 7 days') }}</h3>
            <p class="mb-4 text-sm text-[#52514e]">{{ __('Sales and profit per day (profit over items with a cost price).') }}</p>
            <ul class="mb-3 flex gap-4 text-xs text-[#52514e]">
                <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm" style="background-color: #2a78d6"></span>{{ __('Sales total') }}</li>
                <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm" style="background-color: #1baf7a"></span>{{ __('Profit') }}</li>
            </ul>
            <div class="overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                <div class="flex h-48 min-w-[20rem] items-end gap-2">
                    @foreach($trend as $day)
                        <div class="flex h-full flex-1 items-end justify-center gap-1" title="{{ $day['label'] }} — {{ __('Sales total') }}: {{ $day['revenue_label'] }} · {{ __('Profit') }}: {{ $day['profit_label'] }} · {{ __('Receipts') }}: {{ $day['count'] }}">
                            <div class="w-full max-w-[1.25rem] rounded-t-[3px]" style="height: {{ $day['revenue_h'] }}%; background-color: #2a78d6;"></div>
                            <div class="w-full max-w-[1.25rem] rounded-t-[3px]" style="height: {{ $day['profit_h'] }}%; background-color: #1baf7a;"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex min-w-[20rem] gap-2">
                    @foreach($trend as $day)
                        <span class="flex-1 text-center text-[10px] text-[#52514e]">{{ $day['label'] }}</span>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Hourly chart --}}
    <x-ui.card>
        <div x-data="{ metric: 'sum' }">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-[#0b0b0b]">{{ __('Receipts by hour') }}</h3>
                    <p class="text-sm text-[#52514e]">{{ __('Today by shop; the dashed line is the previous day.') }}</p>
                </div>
                <div class="inline-flex shrink-0 items-center gap-0.5 rounded-lg border border-slate-300 bg-white p-0.5">
                    <button type="button" @click="metric = 'sum'" :class="metric === 'sum' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Total') }}</button>
                    <button type="button" @click="metric = 'count'" :class="metric === 'count' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Qty') }}</button>
                </div>
            </div>

            @if($totals['count'] === 0 && $totals['y_count'] === 0)
                <x-ui.empty-state :title="__('No sales yet today')" :description="__('Sales will appear here as POS terminals send receipts.')" />
            @else
                <ul class="mb-4 flex flex-wrap gap-x-4 gap-y-1.5">
                    @foreach($legend as $item)
                        <li class="flex items-center gap-1.5 text-xs">
                            <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $item['color'] }}"></span>
                            <span class="text-[#52514e]">{{ $item['name'] }}</span>
                        </li>
                    @endforeach
                    <li class="flex items-center gap-1.5 text-xs">
                        <span class="h-0 w-4 shrink-0 border-t-2 border-dashed border-slate-500"></span>
                        <span class="text-[#52514e]">{{ __('Yesterday') }}</span>
                    </li>
                </ul>

                @foreach($charts as $metric => $hours)
                    <div x-show="metric === '{{ $metric }}'" @if($metric !== 'sum') x-cloak @endif
                         class="overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                        <div class="flex h-56 min-w-[34rem] items-end gap-1">
                            @foreach($hours as $h)
                                <div class="relative flex h-full flex-1 flex-col justify-end"
                                     title="{{ $h['hour'] }}:00 — {{ __('Today') }}: {{ $h['today'] }} · {{ __('Yesterday') }}: {{ $h['yesterday'] }}">
                                    <div class="flex flex-col-reverse overflow-hidden rounded-t-[3px]" style="height: {{ $h['height'] }}%;">
                                        @foreach($h['segments'] as $seg)
                                            <div title="{{ $seg['name'] }}: {{ $seg['label'] }}" class="w-full" style="height: {{ $seg['percent'] }}%; background-color: {{ $seg['color'] }};"></div>
                                        @endforeach
                                    </div>
                                    @if($h['marker'] > 0)
                                        <div class="pointer-events-none absolute inset-x-0 border-t-2 border-dashed border-slate-500" style="bottom: {{ $h['marker'] }}%;"></div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-1 flex min-w-[34rem] gap-1">
                            @foreach($hours as $h)
                                <span class="flex-1 text-center text-[10px] text-[#52514e]">{{ $h['hour'] }}</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </x-ui.card>

    {{-- Shops: today vs previous day --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-[#0b0b0b]">{{ __('Shops: today vs yesterday') }}</h3>
        <p class="mb-4 text-sm text-[#52514e]">{{ __('Yesterday is counted up to the same time of day.') }}</p>

        @if(empty($table))
            <x-ui.empty-state :title="__('No sales yet today')" :description="__('Sales will appear here as POS terminals send receipts.')" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Receipts') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Yesterday') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Δ</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Yesterday') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Δ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($table as $row)
                            @php
                                [$cT, $cC] = $deltaBadge($row['count_delta']);
                                [$sT, $sC] = $deltaBadge($row['sum_delta']);
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                    <span class="mr-2 inline-block h-2.5 w-2.5 rounded-sm align-middle" style="background-color: {{ $row['color'] }}"></span>{{ $row['name'] }}
                                </td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-700">{{ $row['count'] }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $row['y_count'] }}</td>
                                <td class="px-4 py-3 text-right text-sm font-medium tabular-nums {{ $cC }}">{{ $cT }}</td>
                                <td class="px-4 py-3 text-right text-sm font-medium tabular-nums text-slate-900">{{ $money($row['sum']) }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $money($row['y_sum']) }}</td>
                                <td class="px-4 py-3 text-right text-sm font-medium tabular-nums {{ $sC }}">{{ $sT }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-sand-50">
                        <tr>
                            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ __('Total') }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums">{{ $totals['count'] }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums">{{ $totals['y_count'] }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums {{ $countDeltaClass }}">{{ $countDeltaText }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums">{{ $money($totals['sum']) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums">{{ $money($totals['y_sum']) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums {{ $sumDeltaClass }}">{{ $sumDeltaText }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Profit by shop --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-[#0b0b0b]">{{ __('Profit by shop') }}</h3>
        <p class="mb-4 text-sm text-[#52514e]">{{ __('Profit over items with a cost price; yesterday is counted up to the same time of day.') }}</p>
        @if(empty($profit['shops']))
            <x-ui.empty-state :title="__('No sales yet today')" :description="__('Sales will appear here as POS terminals send receipts.')" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Profit') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Yesterday') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Δ</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Margin') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($profit['shops'] as $row)
                            @php [$dT, $dC] = $deltaBadge($row['delta']); @endphp
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900"><span class="mr-2 inline-block h-2.5 w-2.5 rounded-sm align-middle" style="background-color: {{ $row['color'] }}"></span>{{ $row['name'] }}</td>
                                <td class="px-4 py-3 text-right text-sm font-medium tabular-nums {{ $row['profit'] < 0 ? 'text-red-700' : 'text-slate-900' }}">{{ $money($row['profit']) }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $money($row['y_profit']) }}</td>
                                <td class="px-4 py-3 text-right text-sm font-medium tabular-nums {{ $dC }}">{{ $dT }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums {{ ($row['margin'] ?? 0) < 0 ? 'text-red-700' : 'text-slate-700' }}">{{ $row['margin'] === null ? '—' : number_format($row['margin'], 1).'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($profit['missing_items'] > 0)
                <p class="mt-3 text-xs text-amber-800">{{ __('Cost missing for :count items — :percent% of revenue is not covered.', ['count' => $profit['missing_items'], 'percent' => $profit['uncovered_percent']]) }}</p>
            @endif
        @endif
    </x-ui.card>

    {{-- Top 20 items --}}
    <x-ui.card>
        <div x-data="{ tab: 'all' }">
            <h3 class="text-base font-semibold text-[#0b0b0b]">{{ __('Top 20 items today') }}</h3>
            <p class="mb-4 text-sm text-[#52514e]">{{ __('Best sellers by quantity, successful sales only.') }}</p>

            @if(empty($topItems['all']))
                <x-ui.empty-state :title="__('No sales yet today')" :description="__('Sales will appear here as POS terminals send receipts.')" />
            @else
                <div class="mb-4 overflow-x-auto border-b border-slate-200">
                    <nav class="-mb-px flex gap-1" role="tablist">
                        <button type="button" @click="tab = 'all'" :class="tab === 'all' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition">{{ __('All shops') }}</button>
                        @foreach($topItems['shops'] as $shopTab)
                            <button type="button" @click="tab = '{{ $shopTab['id'] }}'" :class="tab === '{{ $shopTab['id'] }}' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'" class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition">{{ $shopTab['name'] }}</button>
                        @endforeach
                    </nav>
                </div>

                @php
                    $panels = [['key' => 'all', 'items' => $topItems['all']]];
                    foreach ($topItems['shops'] as $shopTab) {
                        $panels[] = ['key' => (string) $shopTab['id'], 'items' => $shopTab['items']];
                    }
                @endphp
                @foreach($panels as $panel)
                    <div x-show="tab === '{{ $panel['key'] }}'" @if($panel['key'] !== 'all') x-cloak @endif class="max-h-[60vh] overflow-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="sticky top-0 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                                <tr>
                                    <th class="w-12 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Profit') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($panel['items'] as $i => $item)
                                    <tr>
                                        <td class="px-4 py-2.5 text-sm tabular-nums text-slate-400">{{ $i + 1 }}</td>
                                        <td class="px-4 py-2.5 text-sm font-medium text-slate-900">{{ $item['name'] }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm tabular-nums text-slate-700">{{ $item['qty'] }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm tabular-nums text-slate-500">{{ $item['sum'] }}</td>
                                        <td class="px-4 py-2.5 text-right text-sm font-medium tabular-nums {{ $item['profit'] === null ? 'text-slate-400' : ($item['profit_negative'] ? 'text-red-700' : 'text-slate-700') }}" @if($item['profit'] === null) title="{{ __('No cost price') }}" @endif>{{ $item['profit'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endif
        </div>
    </x-ui.card>
</div>
