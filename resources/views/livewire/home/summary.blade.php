@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $rateLabel = fn (string $status) => match ($status) {
        'bad' => __('high'),
        'warn' => __('watch'),
        'ok' => __('normal'),
        default => __('too few receipts'),
    };
    $rateTone = fn (string $status) => match ($status) {
        'bad' => 'bg-red-50 text-red-700',
        'warn' => 'bg-amber-50 text-amber-700',
        default => 'bg-slate-100 text-slate-600',
    };
@endphp
<section wire:poll.120s class="space-y-4" aria-labelledby="home-summary-title">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 id="home-summary-title" class="text-lg font-semibold text-slate-900">{{ $admin ? __('Today in all shops') : __('Today in your shops') }}</h2>
            <p class="text-sm text-slate-500">{{ __('Successful sales so far, compared with yesterday and the same weekday last week at the same time.') }}</p>
        </div>
        <p class="text-xs text-slate-500">
            {{ __('as of :time', ['time' => $asOf]) }}
            <span wire:loading style="display: none;" class="ml-1 text-primary-700">{{ __('Updating...') }}</span>
        </p>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 {{ $profit ? 'xl:grid-cols-4' : 'xl:grid-cols-3' }}">
        <x-home.tile :label="__('Sales total')" :value="$money($cmp['sum']['now'])" :href="route('receipts.index')" :as-of="$asOf" data-tile="sales">
            <x-home.delta :pct="$cmp['sum']['a']" :label="__('vs yesterday')" />
            <x-home.delta :pct="$cmp['sum']['b']" :label="__('vs last week')" />
            @if ($cmp['sum']['incomplete'])
                <x-ui.badge variant="warning">{{ __('Last week incomplete') }}</x-ui.badge>
            @endif
        </x-home.tile>

        <x-home.tile :label="__('Receipts')" :value="$cmp['count']['now']" :href="route('receipts.index')" :as-of="$asOf" data-tile="receipts">
            <x-home.delta :pct="$cmp['count']['a']" :label="__('vs yesterday')" />
            <x-home.delta :pct="$cmp['count']['b']" :label="__('vs last week')" />
        </x-home.tile>

        <x-home.tile :label="__('Average check')" :value="$money($cmp['avg']['now'])" :href="route('receipt-analytics.index')" :as-of="$asOf" data-tile="avg-check">
            <x-home.delta :pct="$cmp['avg']['a']" :label="__('vs yesterday')" />
            <x-home.delta :pct="$cmp['avg']['b']" :label="__('vs last week')" />
        </x-home.tile>

        @if ($profit)
            <x-home.tile :label="__('Profit')" :value="$money($profit['total'])" :href="route('analytics.index')" :as-of="$asOf" :tone="$profit['total'] < 0 ? 'bad' : 'default'" data-tile="profit">
                <x-home.delta :pct="$profit['delta']" :label="__('vs yesterday')" />
                <x-home.delta :pct="$profit['last_week_delta']" :label="__('vs last week')" />
                <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 text-xs font-medium tabular-nums text-slate-700">{{ __('Margin') }} {{ $profit['margin'] === null ? '—' : number_format($profit['margin'], 1).'%' }}</span>
            </x-home.tile>
        @endif
    </div>

    {{-- Rates and stock-outs: a word next to every colour --}}
    <div class="flex flex-wrap gap-2" data-chips>
        <a href="{{ route('receipts.index') }}" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm {{ $rateTone($refundStatus) }}" data-chip="refunds">
            {{ __('Refunds') }}: <span class="font-semibold tabular-nums">{{ number_format($t['refund_amount_percent'], 1) }}%</span>
            <span class="text-xs">({{ $rateLabel($refundStatus) }})</span>
        </a>
        <a href="{{ route('receipts.index') }}" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm {{ $rateTone($cancelledStatus) }}" data-chip="cancelled">
            {{ __('Cancelled') }}: <span class="font-semibold tabular-nums">{{ number_format($t['cancelled_percent'], 1) }}%</span>
            <span class="text-xs">({{ $rateLabel($cancelledStatus) }})</span>
        </a>
        <a href="{{ $admin ? route('ordering.index') : route('dashboard').'#sec-stock-health' }}" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm {{ $stockouts > 0 ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600' }}" data-chip="stockouts">
            {{ __('Out of stock') }}: <span class="font-semibold tabular-nums">{{ $stockouts }}</span>
            <span class="text-xs">({{ $stockouts > 0 ? __('needs action') : __('none') }})</span>
        </a>
    </div>

    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
        @if ($admin)
            <x-ui.card data-block="trend">
                <h3 class="text-sm font-semibold text-slate-900">{{ __('Last 8 days') }}</h3>
                <p class="mb-3 text-xs text-slate-500">{{ __('Sales per day.') }}</p>
                <div class="flex h-24 items-end gap-1.5" role="img" aria-label="{{ __('Sales per day for the last 8 days') }}">
                    @foreach ($trend as $day)
                        <div class="flex h-full flex-1 items-end" title="{{ $day['label'] }}: {{ $day['revenue_label'] }}">
                            <div class="w-full rounded-t-[3px] bg-primary-500" style="height: {{ max($day['revenue_h'], 2) }}%;"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex gap-1.5">
                    @foreach ($trend as $day)
                        <span class="flex-1 text-center text-[10px] text-slate-500">{{ $day['label'] }}</span>
                    @endforeach
                </div>
            </x-ui.card>
        @else
            <x-ui.card data-block="hourly">
                <h3 class="text-sm font-semibold text-slate-900">{{ __('Receipts by hour') }}</h3>
                <p class="mb-3 text-xs text-slate-500">{{ __('Bars = today, line = yesterday.') }}</p>
                <div class="flex h-24 items-end gap-1" role="img" aria-label="{{ __('Receipts by hour, today against yesterday') }}">
                    @foreach ($hours as $h)
                        <div class="relative flex h-full flex-1 items-end" title="{{ $h['hour'] }}:00 — {{ __('today') }}: {{ $h['count'] }}, {{ __('yesterday') }}: {{ $h['y_count'] }}">
                            <div class="w-full rounded-t-[3px] {{ $h['future'] ? 'bg-slate-200' : 'bg-primary-500' }}" style="height: {{ max($h['height'], $h['count'] > 0 ? 3 : 1) }}%;"></div>
                            <div class="absolute inset-x-0 border-t-2 border-slate-700" style="bottom: {{ $h['marker'] }}%;"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex gap-1">
                    @foreach ($hours as $h)
                        <span class="flex-1 text-center text-[10px] {{ $h['current'] ? 'font-semibold text-slate-900' : 'text-slate-500' }}">{{ $h['hour'] }}</span>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        <x-ui.card data-block="leaderboard">
            <h3 class="text-sm font-semibold text-slate-900">{{ $admin ? __('Top shops today') : __('Your shops today') }}</h3>
            <p class="mb-3 text-xs text-slate-500">{{ __('Sales so far, change against yesterday at the same time.') }}</p>
            @if ($shops === [])
                <p class="text-sm text-slate-500">{{ __('No sales yet today') }}</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($shops as $s)
                        <li>
                            <a href="{{ route('shops.show', $s['id']) }}" class="flex items-center gap-2 py-2 text-sm hover:bg-slate-50">
                                <span class="min-w-0 flex-1 truncate font-medium text-slate-900">{{ $s['name'] }}</span>
                                <span class="tabular-nums text-slate-600">{{ $money($s['sum']) }}</span>
                                <x-home.delta :pct="$s['delta']" class="w-20 justify-center" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</section>
