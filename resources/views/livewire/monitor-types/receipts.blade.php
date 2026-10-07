{{-- Receipts analytics monitor, rendered inside livewire/monitor-screen.blade.php (the shared shell).
     Data: $r = App\Services\ReceiptsMetrics::board() (see its metric dictionary). Dark utility classes only,
     so the public light theme (app.css, data-monitor-theme="light") remaps them. --}}
@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $one = fn ($n) => number_format((float) $n, 1, '.', ' ');
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
    // Signed percent against the baseline (+ the absolute change, because a small base makes percent misleading).
    $delta = function (array $d, bool $abs = true, int $decimals = 0) use ($money) {
        if ($d['pct'] === null) {
            return ['—', 'text-slate-500'];
        }
        $text = ($d['pct'] >= 0 ? '▲ +' : '▼ ').number_format($d['pct'], 1).'%';
        if ($abs) {
            $text .= ' ('.($d['diff'] >= 0 ? '+' : '−').number_format(abs($d['diff']), $decimals, '.', ' ').')';
        }

        return [$text, $d['pct'] >= 0 ? 'text-emerald-400' : 'text-red-400'];
    };
    $sev = [
        'high' => ['border-red-500/70 bg-red-500/10', 'text-red-400', __('High')],
        'warn' => ['border-amber-500/60 bg-amber-500/10', 'text-amber-300', __('Watch')],
        'ok' => ['border-slate-800 bg-slate-900', 'text-white', null],
    ];
    $t = $r['today'];
    $y = $r['yesterday'];
    $isEmpty = $t['all'] === 0;
    $panel = 'flex min-h-0 flex-col rounded-2xl border border-slate-800 bg-slate-900 p-[1rem]';
    $panelTitle = 'text-[1.15rem] font-semibold uppercase tracking-wide text-slate-300';
    $base = __('Yesterday by :time', ['time' => $r['asOf']]);
    $shopPages = array_chunk($r['shops'], 8);
    $maxSum = max([1, ...array_map(fn ($s) => $s['today']['sum'], $r['shops'])]);
    $paymentRows = array_slice($r['payments'], 0, 5);
    if (count($r['payments']) > 5) {
        $restPercent = array_sum(array_map(fn ($p) => $p['percent'], array_slice($r['payments'], 5)));
        $paymentRows[] = ['name' => __('Other'), 'percent' => $restPercent, 'sum' => '', 'color' => \App\Services\SalesMetrics::COLOR_OTHER];
    }
    $refundTile = $sev[$r['refundSeverity']];
    $cancelTile = $sev[$r['cancelledSeverity']];
@endphp

    {{-- KPI strip: today, yesterday up to the same time, signed change --}}
    <section class="grid grid-cols-2 gap-[0.9rem] lg:grid-cols-6" aria-label="{{ __('Key figures') }}">
        @php
            $tiles = [
                ['label' => __('Receipts'), 'value' => (string) $t['count'], 'prev' => (string) $y['count'], 'd' => $delta($r['deltas']['count'], true, 0)],
                ['label' => __('Sales total'), 'value' => $money($t['sum']), 'prev' => $money($y['sum']), 'd' => $delta($r['deltas']['sum'], false)],
                ['label' => __('Average check'), 'value' => $money($t['avg']), 'prev' => $money($y['avg']), 'd' => $delta($r['deltas']['avg'], true, 0)],
                ['label' => __('Basket size'), 'value' => $one($t['basket']), 'prev' => $one($y['basket']), 'd' => $delta($r['deltas']['basket'], true, 1), 'hint' => __('lines per receipt')],
            ];
        @endphp
        @foreach($tiles as $tile)
            <div class="{{ $panel }} justify-between gap-1">
                <p class="{{ $panelTitle }}">{{ $tile['label'] }}@isset($tile['hint'])<span class="ml-1 text-[0.8rem] font-normal normal-case text-slate-500">{{ $tile['hint'] }}</span>@endisset</p>
                <p class="tabular truncate text-[3rem] font-bold leading-none text-white">{{ $tile['value'] }}</p>
                <p class="tabular text-[1rem] text-slate-400">
                    {{ $base }}: {{ $tile['prev'] }}
                    <span class="ml-1 font-bold {{ $tile['d'][1] }}">{{ $tile['d'][0] }}</span>
                </p>
                @if($loop->index === 1 && $showProfit && isset($r['profit']))
                    <p class="tabular text-[1rem] font-medium {{ $r['profit']['total'] < 0 ? 'text-red-400' : 'text-slate-200' }}">{{ __('Profit') }} {{ $money($r['profit']['total']) }}@if($r['profit']['margin'] !== null) · {{ __('Margin') }} {{ $one($r['profit']['margin']) }}%@endif</p>
                    @if($r['profit']['missing_items'] > 0)
                        <p class="text-[0.8rem] leading-snug text-amber-300">{{ __('Cost missing for :count items — :percent% of revenue is not covered.', ['count' => $r['profit']['missing_items'], 'percent' => $r['profit']['uncovered_percent']]) }}</p>
                    @endif
                @endif
            </div>
        @endforeach

        {{-- Refunds (judged by the money share of sales) --}}
        <div class="{{ $panel }} {{ $refundTile[0] }} justify-between gap-1">
            <p class="{{ $panelTitle }}">{{ __('Refunds') }}@if($refundTile[2]) <span class="ml-1 rounded bg-red-500/20 px-[0.4rem] text-[0.8rem] font-bold normal-case {{ $refundTile[1] }}">⚠ {{ $refundTile[2] }}</span>@endif</p>
            <p class="tabular truncate text-[3rem] font-bold leading-none {{ $refundTile[1] }}">{{ $t['refund_count'] }}</p>
            <p class="tabular text-[1rem] {{ $refundTile[1] }}">{{ $money($t['refund_sum']) }} · {{ $one($t['refund_amount_percent']) }}% {{ __('of sales') }}</p>
            <p class="tabular text-[0.9rem] text-slate-400">{{ $one($t['refund_percent']) }}% {{ __('of receipts') }} · {{ __('Yesterday') }} {{ $y['refund_count'] }}</p>
        </div>

        {{-- Cancelled (judged by the count share of all receipts) --}}
        <div class="{{ $panel }} {{ $cancelTile[0] }} justify-between gap-1">
            <p class="{{ $panelTitle }}">{{ __('Cancelled') }}@if($cancelTile[2]) <span class="ml-1 rounded bg-red-500/20 px-[0.4rem] text-[0.8rem] font-bold normal-case {{ $cancelTile[1] }}">⚠ {{ $cancelTile[2] }}</span>@endif</p>
            <p class="tabular truncate text-[3rem] font-bold leading-none {{ $cancelTile[1] }}">{{ $t['cancelled_count'] }}</p>
            <p class="tabular text-[1rem] {{ $cancelTile[1] }}">{{ $money($t['cancelled_sum']) }} · {{ $one($t['cancelled_percent']) }}% {{ __('of receipts') }}</p>
            <p class="tabular text-[0.9rem] text-slate-400">{{ __('Yesterday') }} {{ $y['cancelled_count'] }}</p>
        </div>
    </section>

    {{-- Row: receipts per hour / shop comparison --}}
    <section class="grid min-h-0 flex-1 grid-cols-1 gap-[0.9rem] lg:grid-cols-12">
        <div class="{{ $panel }} min-h-[20rem] lg:col-span-5 lg:min-h-0" data-panel="hourly">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="{{ $panelTitle }}">{{ __('Receipts by hour') }}</h2>
                <ul class="flex flex-wrap items-center gap-x-[1rem] gap-y-1 text-[0.9rem] text-slate-300">
                    <li class="flex items-center gap-[0.4rem]"><span class="h-[0.8rem] w-[0.8rem] rounded-sm bg-[#2a78d6]"></span>{{ __('Today') }}</li>
                    <li class="flex items-center gap-[0.4rem]"><span class="w-[1.2rem] border-t-2 border-dashed border-slate-300"></span>{{ $base }}</li>
                </ul>
            </div>
            <div class="relative mt-[1.4rem] min-h-[10rem] flex-1">
                <div class="absolute inset-0 flex gap-[0.2rem]">
                    @foreach($r['hours'] as $h)
                        <div class="relative flex-1 rounded-md {{ $h['current'] ? 'bg-white/10 ring-2 ring-white/40' : '' }}" data-hour="{{ $h['hour'] }}">
                            <div class="monitor-bar absolute inset-x-[10%] bottom-0 rounded-t-[0.25rem] bg-[#2a78d6]" style="height: {{ $h['height'] }}%;"></div>
                            @if($h['marker'] > 0)
                                <div class="pointer-events-none absolute inset-x-0 border-t-2 border-dashed border-slate-300/80" style="bottom: {{ $h['marker'] }}%;"></div>
                            @endif
                            @if($h['count'] > 0)
                                <span class="tabular absolute inset-x-0 -translate-y-full text-center text-[0.9rem] font-semibold text-white" style="bottom: {{ $h['height'] }}%;">{{ $h['count'] }}</span>
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
                @foreach($r['hours'] as $h)
                    <span class="tabular flex-1 text-center text-[0.9rem] {{ $h['current'] ? 'font-bold text-white' : ($h['future'] ? 'text-slate-600' : 'text-slate-400') }}">{{ $h['hour'] }}</span>
                @endforeach
            </div>
        </div>

        <div class="{{ $panel }} lg:col-span-7"
             data-panel="shops" data-pages="{{ max(count($shopPages), 1) }}"
             x-data="{ page: 0, get cur() { return this.page % (Number(this.$root.dataset.pages) || 1); } }"
             x-init="setInterval(() => page++, 20000)">
            <div class="flex items-center justify-between gap-2">
                <h2 class="{{ $panelTitle }}">{{ __('Shops') }}</h2>
                @if(count($shopPages) > 1)
                    <div class="flex items-center gap-[0.4rem] text-[0.9rem] text-slate-400" aria-label="{{ __('Page') }}">
                        @foreach($shopPages as $i => $unused)
                            <span class="h-[0.7rem] w-[0.7rem] rounded-full" :class="cur === {{ $i }} ? 'bg-white' : 'bg-slate-700'"></span>
                        @endforeach
                        <span class="tabular ml-1" x-text="(cur + 1) + '/{{ count($shopPages) }}'">1/{{ count($shopPages) }}</span>
                    </div>
                @endif
            </div>
            @if(empty($r['shops']))
                <div class="flex flex-1 items-center justify-center text-center text-[1.2rem] text-slate-400">{{ __('No shops to show') }}</div>
            @else
                <div class="tabular mt-2 grid grid-cols-[1.6fr_1.1fr_2.2fr_1fr_1.1fr] gap-x-[0.8rem] border-b border-slate-800 pb-1 text-[0.85rem] uppercase text-slate-500">
                    <span>{{ __('Shop') }}</span>
                    <span class="text-right">{{ __('Receipts') }}</span>
                    <span>{{ __('Sales total') }}</span>
                    <span class="text-right">{{ __('Average check') }}</span>
                    <span class="text-right">{{ __('Refunds') }}</span>
                </div>
                @foreach($shopPages as $i => $page)
                    <div class="min-h-0 flex-1 divide-y divide-slate-800/70" data-shop-page="{{ $i }}" @if($i > 0) x-cloak @endif x-show="cur === {{ $i }}">
                        @foreach($page as $s)
                            @php [$cdText, $cdClass] = $delta(['pct' => $s['count_delta'], 'diff' => $s['today']['count'] - $s['yesterday']['count']], false); @endphp
                            <div class="tabular grid grid-cols-[1.6fr_1.1fr_2.2fr_1fr_1.1fr] items-center gap-x-[0.8rem] py-[0.35rem] text-[1.05rem]">
                                <span class="min-w-0 truncate font-semibold text-white">{{ $s['name'] }}
                                    @if(($r['busiest']['name'] ?? null) === $s['name'])<span class="ml-1 text-[0.8rem] font-medium text-emerald-400">▲ {{ __('busiest') }}</span>@endif
                                    @if(($r['quietest']['name'] ?? null) === $s['name'])<span class="ml-1 text-[0.8rem] font-medium text-amber-300">▼ {{ __('quietest') }}</span>@endif
                                </span>
                                <span class="text-right"><b class="text-white">{{ $s['today']['count'] }}</b> <span class="text-[0.8rem] font-semibold {{ $cdClass }}">{{ $cdText }}</span></span>
                                <span class="relative h-[1.5rem] rounded bg-slate-800/60">
                                    <span class="monitor-bar absolute inset-y-0 left-0 rounded bg-[#2a78d6]/60" style="width: {{ $s['today']['sum'] / $maxSum * 100 }}%;"></span>
                                    <span class="absolute inset-y-0 left-[0.5rem] flex items-center font-bold text-white">{{ $money($s['today']['sum']) }}</span>
                                </span>
                                <span class="text-right text-slate-200">{{ $s['today']['count'] > 0 ? $money($s['today']['avg']) : '—' }}</span>
                                <span class="text-right {{ $s['today']['refund_count'] > 0 ? 'font-semibold text-red-300' : 'text-slate-500' }}">{{ $s['today']['refund_count'] > 0 ? $s['today']['refund_count'].' · '.$short($s['today']['refund_sum']) : '0' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    </section>

    {{-- Row: payment mix / highlights / latest receipts --}}
    <section class="grid min-h-0 flex-1 grid-cols-1 gap-[0.9rem] lg:grid-cols-12">
        <div class="{{ $panel }} lg:col-span-3" data-panel="payments">
            <h2 class="{{ $panelTitle }}">{{ __('Payment types') }}</h2>
            @if(empty($paymentRows))
                <div class="flex flex-1 items-center justify-center text-center text-[1.2rem] text-slate-400">{{ __('Waiting for the first sale today') }}</div>
            @else
                <ul class="mt-2 min-h-0 flex-1 space-y-[0.6rem]">
                    @foreach($paymentRows as $pay)
                        <li class="tabular">
                            <div class="flex items-baseline justify-between gap-2 text-[1.1rem]">
                                <span class="min-w-0 truncate font-semibold text-white">{{ $pay['name'] }}</span>
                                <span class="font-bold text-white">{{ number_format($pay['percent'], 0) }}%<span class="ml-2 text-[0.9rem] font-normal text-slate-400">{{ $pay['sum'] }}</span></span>
                            </div>
                            <div class="mt-[0.2rem] h-[0.7rem] rounded bg-slate-800"><div class="monitor-bar h-full rounded" style="width: {{ min(100, $pay['percent']) }}%; background-color: {{ $pay['color'] }};"></div></div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="{{ $panel }} lg:col-span-3" data-panel="highlights">
            <h2 class="{{ $panelTitle }}">{{ __('Highlights') }}</h2>
            <dl class="tabular mt-2 flex min-h-0 flex-1 flex-col justify-around gap-[0.4rem]">
                <div>
                    <dt class="text-[0.9rem] text-slate-400">{{ __('Peak hour') }}</dt>
                    <dd class="text-[1.5rem] font-bold leading-tight text-white">{{ $r['peak'] ? $r['peak']['hour'].':00 · '.$r['peak']['count'].' '.__('receipts') : '—' }}</dd>
                </div>
                @if($r['busiest'])
                    <div>
                        <dt class="text-[0.9rem] text-slate-400">{{ __('Busiest shop') }}</dt>
                        <dd class="truncate text-[1.3rem] font-bold leading-tight text-emerald-400">▲ {{ $r['busiest']['name'] }} · {{ $r['busiest']['count'] }}</dd>
                    </div>
                @endif
                @if($r['quietest'])
                    <div>
                        <dt class="text-[0.9rem] text-slate-400">{{ __('Quietest shop') }}</dt>
                        <dd class="truncate text-[1.3rem] font-bold leading-tight text-amber-300">▼ {{ $r['quietest']['name'] }} · {{ $r['quietest']['count'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[0.9rem] text-slate-400">{{ __('Tills with a sale in the last :minutes min', ['minutes' => \App\Services\ReceiptsMetrics::TILL_WINDOW_MINUTES]) }}</dt>
                    <dd class="text-[1.3rem] font-bold leading-tight {{ $r['tills']['total'] > 0 && $r['tills']['active'] === 0 && ! $isEmpty ? 'text-amber-300' : 'text-white' }}">{{ $r['tills']['active'] }} / {{ $r['tills']['total'] }}</dd>
                </div>
            </dl>
        </div>

        <div class="{{ $panel }} lg:col-span-6" data-panel="latest">
            <h2 class="{{ $panelTitle }}">{{ __('Latest receipts') }}</h2>
            @if(empty($r['latest']))
                <div class="flex flex-1 items-center justify-center text-center text-[1.2rem] text-slate-400">{{ __('Waiting for the first sale today') }}</div>
            @else
                <ul class="mt-2 min-h-0 flex-1 divide-y divide-slate-800 overflow-hidden">
                    @foreach($r['latest'] as $row)
                        <li wire:key="receipt-{{ $row['id'] }}" class="monitor-fade tabular flex items-center gap-[0.7rem] py-[0.25rem] text-[1.05rem]">
                            <span class="w-[5rem] text-slate-400">{{ $row['time'] }}</span>
                            <span class="min-w-0 flex-1 truncate text-slate-100">{{ $row['shop'] }}@if($row['pos'] !== '') <span class="text-[0.9rem] text-slate-400"> · {{ $row['pos'] }}</span>@endif @if($row['payment'] !== '')<span class="text-[0.9rem] text-slate-500"> · {{ $row['payment'] }}</span>@endif</span>
                            @if($row['cancelled'])<span class="rounded bg-amber-500/20 px-[0.4rem] text-[0.8rem] font-semibold uppercase text-amber-300">{{ __('Cancelled') }}</span>@endif
                            @if($row['refund'])<span class="rounded bg-red-500/20 px-[0.4rem] text-[0.8rem] font-semibold uppercase text-red-300">{{ __('Refund') }}</span>@endif
                            <span class="font-bold {{ $row['refund'] ? 'text-red-400' : ($row['cancelled'] ? 'text-slate-500 line-through' : 'text-white') }}">{{ $row['refund'] ? '−' : '' }}{{ $money($row['total']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
