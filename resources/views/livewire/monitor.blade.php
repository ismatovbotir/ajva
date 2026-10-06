@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $short = function ($n) {
        $n = (float) $n;
        if (abs($n) >= 1000000) {
            return rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.').'M';
        }
        if (abs($n) >= 1000) {
            return round($n / 1000).'k';
        }

        return (string) round($n);
    };
    $delta = function (?float $d) {
        if ($d === null) {
            return ['—', 'text-slate-500'];
        }

        return [($d >= 0 ? '▲ +' : '▼ ').number_format($d, 1).'%', $d >= 0 ? 'text-emerald-400' : 'text-red-400'];
    };
    $hours = $charts['sum'];
    $isEmpty = $totals['count'] === 0;
    $panel = 'flex min-h-0 flex-col rounded-2xl border border-slate-800 bg-slate-900 p-[1rem]';
    $panelTitle = 'text-[1.15rem] font-semibold uppercase tracking-wide text-slate-300';
    $topItemsList = array_slice($topItems['all'], 0, 10);
    $leaders = array_slice($table, 0, 8);
    $margin = $showProfit && $profit['margin'] !== null ? number_format($profit['margin'], 1).'%' : '—';
    $peak = collect($charts['sum'])->sortByDesc('total')->first();

    $tiles = [
        ['label' => __('Sales total'), 'value' => $money($totals['sum']), 'prev' => $money($totals['y_sum']), 'delta' => $totals['sum_delta'], 'negative' => false],
        $showProfit
            ? ['label' => __('Profit'), 'value' => $money($profit['total']), 'prev' => $money($profit['yesterday']), 'delta' => $profit['delta'], 'negative' => $profit['total'] < 0, 'extra' => __('Margin').' '.$margin]
            : ['label' => __('Peak hour'), 'value' => $peak && $peak['total'] > 0 ? $peak['hour'].':00' : '—', 'prev' => null, 'delta' => null, 'negative' => false, 'extra' => $peak && $peak['total'] > 0 ? $money($peak['total']) : null],
        ['label' => __('Receipts'), 'value' => (string) $totals['count'], 'prev' => (string) $totals['y_count'], 'delta' => $totals['count_delta'], 'negative' => false, 'extra' => $refunds['count'] > 0 ? __('Refunds').': '.$refunds['count'].' · '.$money($refunds['sum']) : null],
        ['label' => __('Average check'), 'value' => $money($totals['avg']), 'prev' => $money($totals['y_avg']), 'delta' => $totals['avg_delta'], 'negative' => false],
    ];
@endphp
<div
    wire:poll.30s
    x-data="{
        now: Date.now(),
        online: navigator.onLine,
        tz: @js($timezone),
        get stale() { return ! this.online || (this.now - Number($refs.stamp.dataset.ts) * 1000) > 100000; },
        get clock() { return new Date(this.now).toLocaleTimeString('en-GB', { timeZone: this.tz }); },
        get date() { return new Date(this.now).toLocaleDateString('en-GB', { timeZone: this.tz, day: '2-digit', month: '2-digit', year: 'numeric' }).replaceAll('/', '.'); },
        fullscreen() { document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen(); },
    }"
    x-init="setInterval(() => now = Date.now(), 1000)"
    @online.window="online = true"
    @offline.window="online = false"
    class="flex min-h-screen flex-col gap-[0.9rem] p-[1rem] lg:h-screen lg:overflow-hidden"
>
    @if($noShops)
        <p class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-[1.2rem] py-[0.7rem] text-[1.1rem] text-amber-300">{{ __('No shops are assigned to you. Ask an admin.') }}</p>
    @endif

    {{-- Header strip --}}
    <header class="flex flex-wrap items-center justify-between gap-x-[1.5rem] gap-y-2 rounded-2xl border bg-slate-900 px-[1.2rem] py-[0.7rem]"
            :class="stale ? 'border-red-500' : 'border-slate-800'">
        <div class="flex items-center gap-[0.9rem]">
            <span class="flex h-[2.6rem] w-[2.6rem] items-center justify-center rounded-xl bg-brand-600 text-[1.4rem] font-bold text-white">{{ mb_substr(config('app.name'), 0, 1) }}</span>
            <div class="leading-tight">
                <p class="text-[1.4rem] font-semibold text-white">{{ config('app.name') }}</p>
                <p class="text-[0.95rem] text-slate-400">{{ __('Monitor') }} · {{ __('Today') }}</p>
            </div>
        </div>

        <div class="flex items-baseline gap-[1.2rem] tabular">
            <span class="text-[2.6rem] font-bold leading-none text-white" x-text="clock">{{ now()->format('H:i:s') }}</span>
            <span class="text-[1.4rem] text-slate-300" x-text="date">{{ $dateLabel }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-[0.8rem] text-[1rem]">
            @if($alerts['below_min'] > 0 || $alerts['out_of_stock'] > 0)
                <span class="rounded-lg bg-amber-500/15 px-[0.7rem] py-[0.3rem] font-medium text-amber-300">{{ __('Below minimum') }}: <b class="tabular">{{ $alerts['below_min'] }}</b></span>
                <span class="rounded-lg bg-red-500/15 px-[0.7rem] py-[0.3rem] font-medium text-red-300">{{ __('Out of stock') }}: <b class="tabular">{{ $alerts['out_of_stock'] }}</b></span>
            @endif
            <span class="text-slate-400 tabular">{{ __('Updated') }} <span x-ref="stamp" data-ts="{{ $renderedAt }}">{{ \Illuminate\Support\Carbon::createFromTimestamp($renderedAt, $timezone)->format('H:i:s') }}</span></span>
            <span x-show="! stale" class="inline-flex items-center gap-2 rounded-lg bg-emerald-500/15 px-[0.7rem] py-[0.3rem] font-semibold text-emerald-300">
                <span class="h-[0.7rem] w-[0.7rem] rounded-full bg-emerald-400"></span>{{ __('Live') }}
            </span>
            <span x-show="stale" x-cloak class="inline-flex items-center gap-2 rounded-lg bg-red-500 px-[0.7rem] py-[0.3rem] font-bold text-white">
                ⚠ {{ __('Connection lost — data may be outdated') }}
            </span>
            <button type="button" @click="fullscreen()" class="inline-flex items-center gap-2 rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">
                <x-icon name="monitor" class="h-[1.2rem] w-[1.2rem]" />{{ __('Fullscreen') }}
            </button>
            @if($public)
                {{-- Public screen: no link back into the admin panel; instead a Light/Dark switch (remembered per browser). --}}
                <button
                    type="button"
                    x-data="{
                        light: document.documentElement.getAttribute('data-monitor-theme') === 'light',
                        toggle() {
                            this.light = ! this.light;
                            if (this.light) { document.documentElement.setAttribute('data-monitor-theme', 'light'); }
                            else { document.documentElement.removeAttribute('data-monitor-theme'); }
                            try { localStorage.setItem('monitorTheme', this.light ? 'light' : 'dark'); } catch (e) {}
                        },
                    }"
                    @click="toggle()"
                    :aria-pressed="light.toString()"
                    aria-label="{{ __('Light theme') }}"
                    :title="light ? @js(__('Switch to dark theme')) : @js(__('Switch to light theme'))"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                    <span x-show="! light"><x-icon name="sun" class="h-[1.2rem] w-[1.2rem]" /></span>
                    <span x-show="light" x-cloak><x-icon name="moon" class="h-[1.2rem] w-[1.2rem]" /></span>
                    <span x-show="! light">{{ __('Light theme') }}</span><span x-show="light" x-cloak>{{ __('Dark theme') }}</span>
                </button>
            @elseif(Route::has('dashboard') && auth()->user()?->canUsePanel())
                <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">← {{ __('Dashboard') }}</a>
            @else
                {{-- Monitor-only accounts have no panel to go back to; they can only sign out. --}}
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">{{ __('Log out') }}</button>
                </form>
            @endif
        </div>
    </header>

    {{-- KPI strip --}}
    <section class="grid grid-cols-2 gap-[0.9rem] lg:grid-cols-4" aria-label="{{ __('Key figures') }}">
        @foreach($tiles as $tile)
            @php [$dText, $dClass] = $delta($tile['delta']); @endphp
            <div class="{{ $panel }} justify-between gap-1">
                <p class="{{ $panelTitle }}">{{ $tile['label'] }}</p>
                <p class="tabular truncate text-[3.2rem] font-bold leading-none {{ $tile['negative'] ? 'text-red-400' : 'text-white' }}">{{ $tile['value'] }}</p>
                @if($tile['prev'] !== null)
                    <p class="tabular text-[1.05rem] text-slate-400">
                        {{ __('Yesterday') }} {{ $tile['prev'] }}
                        <span class="ml-2 font-bold {{ $dClass }}">{{ $dText }}</span>
                    </p>
                @endif
                @if(! empty($tile['extra']))
                    <p class="tabular text-[1.05rem] font-medium {{ $tile['label'] === __('Receipts') ? 'text-red-300' : 'text-slate-200' }}">{{ $tile['extra'] }}</p>
                @endif
                @if($showProfit && $tile['label'] === __('Profit') && $profit['missing_items'] > 0)
                    <p class="text-[0.85rem] leading-snug text-amber-300">{{ __('Cost missing for :count items — :percent% of revenue is not covered.', ['count' => $profit['missing_items'], 'percent' => $profit['uncovered_percent']]) }}</p>
                @endif
            </div>
        @endforeach
    </section>

    {{-- Row: hourly / payments / leaderboard --}}
    <section class="grid min-h-0 flex-1 grid-cols-1 gap-[0.9rem] lg:grid-cols-12">
        <div class="{{ $panel }} min-h-[22rem] lg:col-span-6 lg:min-h-0">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="{{ $panelTitle }}">{{ __('Sales by hour') }}</h2>
                <ul class="flex flex-wrap items-center gap-x-[1rem] gap-y-1 text-[0.9rem] text-slate-300">
                    @foreach(array_slice($legend, 0, 6) as $item)
                        <li class="flex items-center gap-[0.4rem]"><span class="h-[0.8rem] w-[0.8rem] rounded-sm" style="background-color: {{ $item['color'] }}"></span>{{ $item['name'] }}</li>
                    @endforeach
                    <li class="flex items-center gap-[0.4rem]"><span class="w-[1.2rem] border-t-2 border-dashed border-slate-300"></span>{{ __('Yesterday') }}</li>
                </ul>
            </div>
            <div class="relative mt-[1.4rem] min-h-[12rem] flex-1">
                <div class="absolute inset-0 flex gap-[0.2rem]">
                    @foreach($hours as $i => $h)
                        <div class="relative flex-1 rounded-md {{ $i === $currentHour ? 'bg-white/10 ring-2 ring-white/40' : '' }}">
                            <div class="monitor-bar absolute inset-x-[10%] bottom-0 flex flex-col-reverse overflow-hidden rounded-t-[0.25rem]" style="height: {{ $h['height'] }}%;">
                                @foreach($h['segments'] as $seg)
                                    <div class="w-full" style="height: {{ $seg['percent'] }}%; background-color: {{ $seg['color'] }};"></div>
                                @endforeach
                            </div>
                            @if($h['marker'] > 0)
                                <div class="pointer-events-none absolute inset-x-0 border-t-2 border-dashed border-slate-300/80" style="bottom: {{ $h['marker'] }}%;"></div>
                            @endif
                            @if($h['total'] > 0)
                                <span class="tabular absolute inset-x-0 -translate-y-full text-center text-[0.8rem] font-semibold text-white" style="bottom: {{ $h['height'] }}%;">{{ $short($h['total']) }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if($isEmpty)
                    <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                        <p class="text-[1.8rem] font-semibold text-slate-200">{{ __('Waiting for the first sale today') }}</p>
                        <p class="text-[1.1rem] text-slate-400">{{ __('New receipts appear here automatically.') }}</p>
                    </div>
                @endif
            </div>
            <div class="mt-1 flex gap-[0.2rem]">
                @foreach($hours as $i => $h)
                    <span class="tabular flex-1 text-center text-[0.85rem] {{ $i === $currentHour ? 'font-bold text-white' : ($i > $currentHour ? 'text-slate-600' : 'text-slate-400') }}">{{ $h['hour'] }}</span>
                @endforeach
            </div>
        </div>

        <div class="{{ $panel }} lg:col-span-3">
            <h2 class="{{ $panelTitle }}">{{ __('Payment types') }}</h2>
            @if(empty($payments))
                <div class="flex flex-1 items-center justify-center text-center text-[1.2rem] text-slate-400">{{ __('Waiting for the first sale today') }}</div>
            @else
                @php $circ = 2 * M_PI * 38; $acc = 0.0; @endphp
                <div class="mt-2 flex min-h-0 flex-1 flex-col items-center gap-[0.8rem] sm:flex-row lg:flex-col xl:flex-row">
                    <svg viewBox="0 0 100 100" class="h-[11rem] w-[11rem] shrink-0 lg:h-[9rem] lg:w-[9rem] xl:h-[11rem] xl:w-[11rem]" role="img" aria-label="{{ __('Payment types') }}">
                        <g transform="rotate(-90 50 50)">
                            @foreach($payments as $pay)
                                @php $len = $pay['percent'] / 100 * $circ; @endphp
                                <circle cx="50" cy="50" r="38" fill="none" stroke="{{ $pay['color'] }}" stroke-width="16" stroke-dasharray="{{ number_format($len, 3, '.', '') }} {{ number_format($circ - $len, 3, '.', '') }}" stroke-dashoffset="{{ number_format(-$acc, 3, '.', '') }}"></circle>
                                @php $acc += $len; @endphp
                            @endforeach
                        </g>
                        <text x="50" y="52" text-anchor="middle" fill="currentColor" class="text-white" font-size="11" font-weight="700">{{ $short($totals['sum']) }}</text>
                    </svg>
                    <ul class="w-full min-w-0 flex-1 space-y-[0.5rem]">
                        @foreach(array_slice($payments, 0, 5) as $pay)
                            <li class="tabular">
                                <div class="flex items-center gap-[0.5rem] text-[1.1rem]">
                                    <span class="h-[0.9rem] w-[0.9rem] shrink-0 rounded-sm" style="background-color: {{ $pay['color'] }}"></span>
                                    <span class="min-w-0 flex-1 truncate font-semibold text-white">{{ $pay['name'] }}</span>
                                    <span class="font-bold text-white">{{ number_format($pay['percent'], 0) }}%</span>
                                </div>
                                <p class="pl-[1.4rem] text-[0.9rem] text-slate-400">{{ $pay['sum'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="{{ $panel }} lg:col-span-3">
            <h2 class="{{ $panelTitle }}">{{ __('Shops: share of sales') }}</h2>
            @if(empty($leaders))
                <div class="flex flex-1 items-center justify-center text-center text-[1.2rem] text-slate-400">{{ __('Waiting for the first sale today') }}</div>
            @else
                @php
                    // Each shop's share of today's successful sales (refunds are not part of this).
                    $shareTotal = array_sum(array_map(fn ($r) => max($r['sum'], 0), $table));
                    $pieCirc = 2 * M_PI * 25;
                    $pieAcc = 0.0;
                    $leaders = array_slice($table, 0, 6);
                @endphp
                <div class="mt-2 flex min-h-0 flex-1 flex-col items-center gap-[0.8rem] lg:flex-col xl:flex-row">
                    {{-- Pie: a full-radius stroke draws a filled slice per shop --}}
                    <svg viewBox="0 0 100 100" class="h-[9rem] w-[9rem] shrink-0 xl:h-[10rem] xl:w-[10rem]" role="img" aria-label="{{ __('Shops: share of sales') }}">
                        <g transform="rotate(-90 50 50)">
                            @foreach($table as $row)
                                @php $len = $shareTotal > 0 ? max($row['sum'], 0) / $shareTotal * $pieCirc : 0; @endphp
                                @if($len > 0)
                                    <circle cx="50" cy="50" r="25" fill="none" stroke="{{ $row['color'] }}" stroke-width="50" stroke-dasharray="{{ number_format($len, 3, '.', '') }} {{ number_format($pieCirc - $len, 3, '.', '') }}" stroke-dashoffset="{{ number_format(-$pieAcc, 3, '.', '') }}"></circle>
                                    @php $pieAcc += $len; @endphp
                                @endif
                            @endforeach
                        </g>
                        <circle cx="50" cy="50" r="49.5" fill="none" stroke="#0f172a" stroke-width="1"></circle>
                    </svg>
                    <ol class="min-h-0 w-full min-w-0 flex-1 space-y-[0.4rem] overflow-hidden">
                        @foreach($leaders as $i => $row)
                            @php
                                $share = $shareTotal > 0 ? max($row['sum'], 0) / $shareTotal * 100 : 0;
                                [$sdText, $sdClass] = $delta($row['sum_delta']);
                            @endphp
                            <li class="tabular leading-tight">
                                <div class="flex items-center gap-[0.5rem] text-[1.05rem]">
                                    <span class="h-[0.9rem] w-[0.9rem] shrink-0 rounded-sm" style="background-color: {{ $row['color'] }}"></span>
                                    <span class="min-w-0 flex-1 truncate font-semibold text-white">{{ $row['name'] }}</span>
                                    <span class="font-bold text-white">{{ number_format($share, $share < 10 ? 1 : 0) }}%</span>
                                </div>
                                <div class="flex items-baseline justify-between gap-2 pl-[1.4rem] text-[0.85rem] text-slate-400">
                                    <span>{{ $money($row['sum']) }}</span>
                                    <span class="font-semibold {{ $sdClass }}">{{ $sdText }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
        </div>
    </section>

    {{-- Row: top items / 7 days / ticker --}}
    <section class="grid min-h-0 flex-1 grid-cols-1 gap-[0.9rem] lg:grid-cols-12">
        <div class="{{ $panel }} lg:col-span-4">
            <h2 class="{{ $panelTitle }}">{{ __('Top 10 items today') }}</h2>
            @if(empty($topItemsList))
                <div class="flex flex-1 items-center justify-center text-center text-[1.2rem] text-slate-400">{{ __('Waiting for the first sale today') }}</div>
            @else
                <table class="tabular mt-2 w-full table-fixed text-[1rem]">
                    <thead>
                        <tr class="text-[0.85rem] uppercase text-slate-500">
                            <th class="w-[1.6rem] text-left font-medium">#</th>
                            <th class="text-left font-medium">{{ __('Item') }}</th>
                            <th class="w-[4rem] text-right font-medium">{{ __('Qty') }}</th>
                            <th class="w-[6rem] text-right font-medium">{{ __('Total') }}</th>
                            @if($showProfit)<th class="w-[6rem] text-right font-medium">{{ __('Profit') }}</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach($topItemsList as $i => $item)
                            <tr>
                                <td class="py-[0.18rem] text-slate-500">{{ $i + 1 }}</td>
                                <td class="truncate py-[0.18rem] font-medium text-white">{{ $item['name'] }}</td>
                                <td class="py-[0.18rem] text-right font-semibold text-slate-100">{{ $item['qty'] }}</td>
                                <td class="py-[0.18rem] text-right text-slate-300">{{ $item['sum'] }}</td>
                                @if($showProfit)<td class="py-[0.18rem] text-right font-semibold {{ $item['profit'] === null ? 'text-slate-600' : ($item['profit_negative'] ? 'text-red-400' : 'text-emerald-400') }}">{{ $item['profit'] ?? '—' }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="{{ $panel }} min-h-[18rem] lg:col-span-4 lg:min-h-0">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="{{ $panelTitle }}">{{ __('Last 7 days') }}</h2>
                <ul class="flex gap-[1rem] text-[0.9rem] text-slate-300">
                    <li class="flex items-center gap-[0.4rem]"><span class="h-[0.8rem] w-[0.8rem] rounded-sm bg-[#2a78d6]"></span>{{ __('Sales total') }}</li>
                    @if($showProfit)<li class="flex items-center gap-[0.4rem]"><span class="h-[0.8rem] w-[0.8rem] rounded-sm bg-[#1baf7a]"></span>{{ __('Profit') }}</li>@endif
                </ul>
            </div>
            <div class="relative mt-[1.4rem] min-h-[8rem] flex-1">
                <div class="absolute inset-0 flex gap-[0.5rem]">
                    @foreach($trend as $i => $day)
                        <div class="relative flex-1 rounded-md {{ $loop->last ? 'bg-white/10 ring-2 ring-white/40' : '' }}">
                            <div class="absolute inset-x-[8%] bottom-0 top-0 flex items-end gap-[0.15rem]">
                                <div class="monitor-bar relative flex-1 rounded-t-[0.25rem] bg-[#2a78d6]" style="height: {{ $day['revenue_h'] }}%;">
                                    @if($day['revenue'] > 0)<span class="tabular absolute inset-x-[-0.5rem] bottom-full text-center text-[0.75rem] font-semibold text-slate-100">{{ $short($day['revenue']) }}</span>@endif
                                </div>
                                @if($showProfit)<div class="monitor-bar relative flex-1 rounded-t-[0.25rem] bg-[#1baf7a]" style="height: {{ $day['profit_h'] }}%;"></div>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="mt-1 flex gap-[0.5rem]">
                @foreach($trend as $day)
                    <span class="tabular flex-1 text-center text-[0.9rem] {{ $loop->last ? 'font-bold text-white' : 'text-slate-400' }}">{{ $day['label'] }}</span>
                @endforeach
            </div>
        </div>

        <div class="{{ $panel }} lg:col-span-4">
            <h2 class="{{ $panelTitle }}">{{ __('Latest receipts') }}</h2>
            @if(empty($latest))
                <div class="flex flex-1 items-center justify-center text-center text-[1.2rem] text-slate-400">{{ __('Waiting for the first sale today') }}</div>
            @else
                <ul class="mt-2 min-h-0 flex-1 divide-y divide-slate-800 overflow-hidden">
                    @foreach($latest as $r)
                        <li wire:key="receipt-{{ $r['id'] }}" class="monitor-fade tabular flex items-center gap-[0.7rem] py-[0.3rem] text-[1.05rem]">
                            <span class="w-[5rem] text-slate-400">{{ $r['time'] }}</span>
                            <span class="min-w-0 flex-1 truncate text-slate-100">{{ $r['shop'] }}@if($r['payment'] !== '') <span class="text-[0.9rem] text-slate-500"> · {{ $r['payment'] }}</span>@endif</span>
                            @if($r['refund'])<span class="rounded bg-red-500/20 px-[0.4rem] text-[0.8rem] font-semibold uppercase text-red-300">{{ __('Refund') }}</span>@endif
                            <span class="font-bold {{ $r['refund'] ? 'text-red-400' : 'text-white' }}">{{ $r['refund'] ? '−' : '' }}{{ $r['total'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
</div>
