@php
    $money = fn ($n) => $n === null ? '—' : number_format((float) $n, 0, '.', ' ');
    $num = fn ($n, $d = 1) => $n === null ? '—' : number_format((float) $n, $d, '.', ' ');
    $pct = fn ($n, $d = 1) => $n === null ? '—' : number_format((float) $n, $d, '.', ' ').' %';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $cfg = config('sales_gaps');
    $weekdays = [__('Sunday'), __('Monday'), __('Tuesday'), __('Wednesday'), __('Thursday'), __('Friday'), __('Saturday')];
    $actionLabel = [
        'check' => __('Raise the average check'),
        'card' => __('Add customers with a card'),
        'availability' => __('Keep class A items in stock'),
        'peak' => __('Open a second register at peak hours'),
    ];
    $naReason = fn ($why) => match ($why) {
        'receipts' => __('Not enough data: fewer than :n sale receipts.', ['n' => $cfg['min_receipts']]),
        'benchmark' => __('Not enough data: too few shops with enough receipts for a benchmark.'),
        'chain_card_receipts' => __('Not enough data: the chain has fewer than :n card receipts.', ['n' => $cfg['card']['min_chain_card_receipts']]),
        'card_check_not_higher' => __('Not shown: card receipts are not larger than the others.'),
        default => __('Not enough data.'),
    };
    $basis = fn ($b) => match (true) {
        $b === 'median' => __('the chain median (fewer than :n qualifying shops)', ['n' => $cfg['benchmark_min_shops']]),
        str_starts_with((string) $b, 'p') => __('the chain P:p', ['p' => substr($b, 1)]),
        default => '—',
    };
    $selectedRow = $generated && $selected !== null ? collect($report['shops'] ?? [])->firstWhere('id', $selected) : null;
    $ordersLink = $isAdmin ? route('ordering.index') : route('orders.index');
@endphp
<div>
    <x-ui.page-header :title="__('Sales gaps')" :subtitle="__('Where each shop sells less than the chain\'s own good shops. Estimates for review, not promises.')" />

    <form wire:submit="generate" class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-44">
            <x-ui.label for="dateFrom">{{ __('From') }}</x-ui.label>
            <input type="date" id="dateFrom" wire:model="dateFrom" max="{{ now()->subDay()->toDateString() }}"
                   class="block h-11 w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
            <x-ui.error name="dateFrom" />
        </div>
        <div class="w-full sm:w-44">
            <x-ui.label for="dateTo">{{ __('To') }}</x-ui.label>
            <input type="date" id="dateTo" wire:model="dateTo" max="{{ now()->subDay()->toDateString() }}"
                   class="block h-11 w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
            <x-ui.error name="dateTo" />
        </div>

        <div class="relative w-full sm:w-72" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <x-ui.label>{{ __('Shops') }}</x-ui.label>
            <button type="button" @click="open = !open"
                    class="flex h-11 w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                <span class="truncate">{{ count($shopIds) === 0 ? __('All shops') : __(':count shops selected', ['count' => count($shopIds)]) }}</span>
                <span class="text-[10px] text-slate-400">▼</span>
            </button>
            <div x-show="open" x-cloak class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                @foreach($shops as $shop)
                    <label wire:key="shop-{{ $shop->id }}" class="flex cursor-pointer items-center gap-2 rounded px-2 py-2 text-sm hover:bg-slate-50">
                        <input type="checkbox" wire:model="shopIds" value="{{ $shop->id }}" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />
                        <span class="truncate">{{ $shop->name }}</span>
                    </label>
                @endforeach
                <button type="button" wire:click="$set('shopIds', [])" class="mt-1 w-full rounded px-2 py-2 text-left text-xs text-slate-500 hover:bg-slate-50">{{ __('Clear selection (all shops)') }}</button>
            </div>
        </div>

        <x-ui.button type="submit" class="h-11" wire:loading.attr="disabled" wire:target="generate">
            <span wire:loading.remove wire:target="generate">{{ __('Generate') }}</span>
            <span wire:loading wire:target="generate" style="display: none;">{{ __('Generating report') }}…</span>
        </x-ui.button>
    </form>
    <p class="mb-4 text-xs text-slate-500">{{ __('Default period: the last :n whole days ending yesterday (:min to :max days). Only whole days are used.', ['n' => $cfg['default_days'], 'min' => $cfg['min_days'], 'max' => $cfg['max_days']]) }}</p>

    <details class="mb-6 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
        <summary class="cursor-pointer font-semibold text-slate-900">{{ __('How this is calculated') }}</summary>
        <ul class="mt-3 list-disc space-y-1.5 pl-5">
            <li>{{ __('Sale = active and sell receipt; storno lines are left out. Sales are net of refunds of the same day. Shop days when the receipt feed was silent are left out and counted.') }}</li>
            <li>{{ __('Average check gap: the trimmed mean of receipt totals (receipts in the chain\'s top 5 % are left out on both sides) against the chain P:p of shops with at least :n sale receipts (the median when fewer than :s such shops). Recoverable = :f x the gap x receipts per day x 30, shown capped at :c % of the shop\'s monthly sales and uncapped. Flag: more than :g % below.', ['p' => $cfg['benchmark_percentile'], 'n' => $cfg['min_receipts'], 's' => $cfg['benchmark_min_shops'], 'f' => $cfg['check']['recoverable_factor'], 'c' => $cfg['check']['cap_share'] * 100, 'g' => $cfg['check']['flag_gap_pct']]) }}</li>
            <li>{{ __('Card share: receipts with customer data. Shown only when the chain has at least :n card receipts and its card check is not below the others. Uplift per 30 days is an upper bound from a correlation, not proof, multiplied by :d. No conclusion about cashiers.', ['n' => $cfg['card']['min_chain_card_receipts'], 'd' => $cfg['card']['damping']]) }}</li>
            <li>{{ __('Availability: class A items that are out now, reported by the latest 1C sync, with a trusted sales speed (not low confidence, at least :u days of history). Lost per day = speed x price x recoverable share :r. Period total = days out in the period x lost per day. A day-level estimate: stock is a daily snapshot, hourly stock-outs are invisible. Items not reported by 1C are only counted.', ['u' => $cfg['availability']['min_usable_days'], 'r' => $cfg['availability']['recoverable_share']]) }}</li>
            <li>{{ __('Peak hours: shops with at least :r registers; an hour counts as a peak when its receipts exceed :x x the median of the same weekday and hour on the other days, only one register was used, and it repeats on at least :d days. A signal to review, shop-local time.', ['r' => $cfg['peak']['min_registers'], 'x' => $cfg['peak']['ratio'], 'd' => $cfg['peak']['min_days']]) }}</li>
            <li>{{ __('The levers overlap (a better check, more card customers and fewer stock-outs act on the same receipts), so they are never added together. All money figures are estimates.') }}</li>
        </ul>
    </details>

    @if($noShops)
        <x-ui.no-shops />
    @elseif(! $generated)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick the period and shops, then press Generate.')" />
    @elseif(($report['shops'] ?? []) === [])
        <x-ui.empty-state :title="__('No shops')" :description="__('There are no shops to analyse for this selection.')" />
    @else
        @php($k = $report['kpi'])
        @php($w = $report['window'])
        @php($bc = $report['benchmarks']['check'])
        @php($bk = $report['benchmarks']['card'])

        <p class="mb-3 text-xs text-slate-500">
            {{ __('Figures as of :as_of. Period: :from to :to (:days days). Per day = per day with receipt data.', ['as_of' => $report['as_of'], 'from' => $w['from'], 'to' => $w['to'], 'days' => $w['days']]) }}
        </p>

        {{-- KPI strip --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ __('Shops analysed') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['shops']) }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __(':n sale receipts in the sample', ['n' => $money($k['receipts'])]) }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ __('Shops with a flag') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $k['flagged'] > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ $money($k['flagged']) }}@if($k['flagged'] > 0) <span class="sr-only">{{ __('needs attention') }}</span><span aria-hidden="true" class="text-base">&#9888;</span>@endif</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Any lever below, see the table') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ __('Check benchmark') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $bc['value'] === null ? '—' : $money($bc['value']) }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if($bc['value'] === null) {{ __('Not enough data: :n shops with enough receipts.', ['n' => $bc['shops']]) }}
                    @else {{ $basis($bc['basis']) }}, {{ __(':n shops', ['n' => $bc['shops']]) }}, {{ __('trimmed mean') }} @endif
                </p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ __('Card share target') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $bk['value'] === null ? '—' : $pct($bk['value'] * 100) }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if($bk['value'] === null) {{ $naReason($bk['reason'] ?? 'benchmark') }}
                    @else {{ $basis($bk['basis']) }}, {{ __(':n shops', ['n' => $bk['shops']]) }} @endif
                </p>
            </x-ui.card>
        </div>
        @if($k['silent_shop_days'] > 0)
            <p class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-700">{{ __(':n shop days with a silent receipt feed were left out (missing data, not zero sales).', ['n' => $k['silent_shop_days']]) }}</p>
        @endif

        {{-- Shop table --}}
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Shops') }}</h2>
        <p class="mb-2 text-xs text-slate-500">{{ __('Sorted by the largest single lever per 30 days. The levers overlap and are not added together. Check gap: positive = below the chain.') }}</p>

        <div class="mb-6 space-y-3 md:hidden">
            @foreach($report['shops'] as $r)
                <button type="button" wire:key="card-{{ $r['id'] }}" wire:click="selectShop({{ $r['id'] }})"
                        class="block w-full rounded-lg border bg-white p-4 text-left {{ $selected === $r['id'] ? 'border-brand-600 ring-1 ring-brand-600' : 'border-slate-200' }}">
                    <p class="flex items-center justify-between gap-2"><span class="font-medium text-slate-900">{{ $r['name'] }}</span>
                        @if($r['top_action'])<x-ui.badge variant="warning">{{ $actionLabel[$r['top_action']] }}</x-ui.badge>@endif</p>
                    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-600">
                        <div><dt>{{ __('Sales per day') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $money($r['sales_per_day']) }}</dd></div>
                        <div><dt>{{ __('Receipts per day') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $num($r['receipts_per_day'], 0) }}</dd></div>
                        <div><dt>{{ __('Average check') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $money($r['avg_check']) }}</dd></div>
                        <div><dt>{{ __('Check gap') }}</dt><dd class="tabular-nums text-sm {{ $r['check']['flag'] ? 'font-semibold text-red-700' : 'text-slate-900' }}">{{ $r['check']['status'] === 'ok' ? $pct($r['check']['gap_pct']) : '—' }}</dd></div>
                        <div><dt>{{ __('Basket') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $num($r['basket'], 2) }}</dd></div>
                        <div><dt>{{ __('Card share') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $r['card']['status'] === 'ok' ? $pct($r['card']['share']) : '—' }}</dd></div>
                        <div><dt>{{ __('Class A out') }}</dt><dd class="tabular-nums text-sm {{ $r['availability']['out_a'] > 0 ? 'font-semibold text-red-700' : 'text-slate-900' }}">{{ $r['availability']['out_a'] }}</dd></div>
                        @if($k['has_area'])
                            <div><dt>{{ __('Sales per m2') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $money($r['sales_per_m2']) }}</dd></div>
                        @endif
                    </dl>
                </button>
            @endforeach
        </div>

        <x-ui.card padding="p-0" class="mb-6 hidden md:block">
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                <thead><tr>
                    <th class="{{ $th }} text-left">{{ __('Shop') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Sales per day') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Receipts per day') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Average check') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Check gap') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Basket') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Card share') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Class A out') }}</th>
                    @if($k['has_area'])
                        <th class="{{ $th }} text-right">{{ __('Sales per m2') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Receipts per m2') }}</th>
                    @endif
                    <th class="{{ $th }} text-left">{{ __('Top action') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                @foreach($report['shops'] as $r)
                    <tr wire:key="row-{{ $r['id'] }}" class="{{ $selected === $r['id'] ? 'bg-brand-50' : '' }}">
                        <td class="px-3 py-2"><button type="button" wire:click="selectShop({{ $r['id'] }})" class="font-medium text-brand-700 hover:underline">{{ $r['name'] }}</button>
                            @if($r['silent_days'] > 0)<span class="ml-1 text-xs text-slate-500">{{ __(':n silent days', ['n' => $r['silent_days']]) }}</span>@endif</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $money($r['sales_per_day']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['receipts_per_day'], 0) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $money($r['avg_check']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $r['check']['flag'] ? 'font-semibold text-red-700' : '' }}">{{ $r['check']['status'] === 'ok' ? $pct($r['check']['gap_pct']) : '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['basket'], 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r['card']['status'] === 'ok' ? $pct($r['card']['share']) : '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $r['availability']['out_a'] > 0 ? 'font-semibold text-red-700' : '' }}">{{ $r['availability']['out_a'] }}</td>
                        @if($k['has_area'])
                            <td class="px-3 py-2 text-right tabular-nums">{{ $money($r['sales_per_m2']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['receipts_per_m2'], 1) }}</td>
                        @endif
                        <td class="px-3 py-2">@if($r['top_action'])<x-ui.badge variant="warning">{{ $actionLabel[$r['top_action']] }}</x-ui.badge>@else<span class="text-slate-400">—</span>@endif</td>
                    </tr>
                @endforeach
                </tbody></table></div>
        </x-ui.card>
        <p class="-mt-4 mb-6 text-xs text-slate-500">{{ __('Dash = not enough data (for example fewer than :n sale receipts) or not filled in. Sales and receipts per m2 are per 30 days and need the shop area on the shop page.', ['n' => $cfg['min_receipts']]) }}</p>

        {{-- Action panel --}}
        @if($selectedRow)
            @php($s = $selectedRow)
            @php($av = $s['availability'])
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('What to do in :shop', ['shop' => $s['name']]) }}</h2>
            <x-ui.card class="mb-6">
                <p class="mb-3 text-xs text-slate-500">{{ __('As of :as_of. Each line is a separate estimate; they overlap and are not added together.', ['as_of' => $report['as_of']]) }}</p>
                <ul class="space-y-4 text-sm text-slate-800">
                    {{-- Check --}}
                    <li>
                        <p class="font-semibold text-slate-900">{{ __('Average check') }}</p>
                        @if($s['check']['status'] === 'ok')
                            <p>{{ __('Trimmed check :c against :b (:basis): :g % below the chain.', ['c' => $money($s['check']['trim_check']), 'b' => $money($s['check']['benchmark']), 'basis' => $basis($s['check']['basis']), 'g' => $num($s['check']['gap_pct'])]) }}
                                @if(! $s['check']['flag'] && $s['check']['gap_pct'] <= 0) {{ __('This is at or above the chain, nothing to close.') }} @endif</p>
                            @if($s['check']['flag'])
                                <p>{{ __('Possible, per 30 days: :capped (capped at :cs % of sales), :raw uncapped.', ['capped' => $money($s['check']['recoverable_capped']), 'cs' => $cfg['check']['cap_share'] * 100, 'raw' => $money($s['check']['recoverable'])]) }}</p>
                                <p>{{ __('Action: offer add-on items at the till and review the basket (:u lines per receipt now).', ['u' => $num($s['basket'], 2)]) }}
                                    <a href="{{ route('receipt-analytics.index') }}" class="text-brand-700 hover:underline">{{ __('Receipt analytics') }}</a></p>
                            @endif
                        @else
                            <p class="text-slate-600">{{ $naReason($s['check']['reason']) }}</p>
                        @endif
                    </li>
                    {{-- Card --}}
                    <li>
                        <p class="font-semibold text-slate-900">{{ __('Card share') }}</p>
                        @if($s['card']['status'] === 'ok')
                            <p>{{ __('Share :s against the target :t.', ['s' => $pct($s['card']['share']), 't' => $pct($s['card']['target'])]) }}</p>
                            @if($s['card']['flag'])
                                <p>{{ __('Upper bound per 30 days: :m (correlation, not proof, damped).', ['m' => $money($s['card']['uplift'])]) }}</p>
                                <p>{{ __('Action: ask for the card at every receipt.') }} <a href="{{ route('receipt-analytics.index') }}" class="text-brand-700 hover:underline">{{ __('Receipt analytics') }}</a></p>
                            @endif
                        @else
                            <p class="text-slate-600">{{ $naReason($s['card']['reason']) }}</p>
                        @endif
                    </li>
                    {{-- Availability --}}
                    <li>
                        <p class="font-semibold text-slate-900">{{ __('Class A availability') }}</p>
                        @if($av['out_a'] === 0)
                            <p class="text-slate-600">{{ __('No class A item is out of stock in the latest sync.') }}</p>
                        @else
                            <p>{{ __(':n class A items are out now (stock as of :d); :v with a trusted speed, :u unknown, :p without a price.', ['n' => $av['out_a'], 'd' => $report['stock_as_of'] ?? '—', 'v' => $av['valued'], 'u' => $av['unknown'], 'p' => $av['no_price']]) }}</p>
                            @if($av['valued'] > 0)
                                <p>{{ __('Run-rate today: :a per day lost (recoverable share :r). Period total: :b.', ['a' => $money($av['lost_per_day']), 'r' => $cfg['availability']['recoverable_share'], 'b' => $money($av['lost_period'])]) }}
                                    <span class="text-xs text-slate-500">{{ __('Day-level estimate: stock is a daily snapshot, hourly stock-outs are invisible.') }}</span></p>
                            @endif
                            <p>{{ __('Action: replenish these items first.') }} <a href="{{ $ordersLink }}" class="text-brand-700 hover:underline">{{ $isAdmin ? __('Ordering') : __('Orders') }}</a></p>
                            <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                                @foreach($av['items'] as $it)
                                    <li wire:key="it-{{ $s['id'] }}-{{ $it['item_id'] }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                        <a href="{{ route('items.show', $it['item_id']) }}" class="text-brand-700 hover:underline">{{ $it['name'] }}</a>
                                        <span class="text-xs tabular-nums text-slate-600">
                                            @if($it['state'] === 'valued') {{ __(':a per day, :d days out in the period', ['a' => $money($it['per_day']), 'd' => $it['days_out']]) }}
                                            @elseif($it['state'] === 'no_price') {{ __('No price') }}
                                            @else {{ __('Unknown: not enough sales history') }} @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if($av['not_reported'] > 0)
                            <p class="mt-1 text-xs text-slate-500">{{ __(':n item pairs were not reported by the latest 1C sync: counted, not valued.', ['n' => $av['not_reported']]) }}</p>
                        @endif
                    </li>
                    {{-- Peak hours --}}
                    <li>
                        <p class="font-semibold text-slate-900">{{ __('Peak hours') }}</p>
                        @forelse($s['peaks'] as $p)
                            <p wire:key="peak-{{ $s['id'] }}-{{ $p['weekday'] }}-{{ $p['hour'] }}">{{ __(':wd :h:00: about :a receipts in the hour against a usual :m, on :d days, one register only. Signal to review.', ['wd' => $weekdays[$p['weekday']], 'h' => sprintf('%02d', $p['hour']), 'a' => $num($p['avg_receipts'], 0), 'm' => $num($p['median'], 0), 'd' => $p['days']]) }}
                                <a href="{{ route('receipts.index', ['date' => $p['example_date']]) }}" class="text-brand-700 hover:underline">{{ __('Receipts of :d', ['d' => $p['example_date']]) }}</a></p>
                        @empty
                            <p class="text-slate-600">{{ __('No repeating peak with a single register found.') }}</p>
                        @endforelse
                    </li>
                </ul>
            </x-ui.card>
        @else
            <p class="mb-6 text-xs text-slate-500">{{ __('Click a shop to see what to do there.') }}</p>
        @endif
    @endif
</div>
