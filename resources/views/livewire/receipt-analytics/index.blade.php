@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $num = fn ($n, $d = 1) => number_format((float) $n, $d, '.', ' ');
    $pct = fn ($n) => number_format((float) $n, 1, '.', ' ').' %';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp
<div>
    <x-ui.page-header :title="__('Receipt analytics')" :subtitle="__('Discounts, big receipts and item relations for the selected period and shops.')" />

    <form wire:submit="generate" class="mb-6 flex flex-wrap items-end gap-3">
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

        {{-- Shop multi-select --}}
        <div class="relative w-full sm:w-72" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <x-ui.label>{{ __('Shops') }}</x-ui.label>
            <button type="button" @click="open = !open"
                    class="flex w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                <span class="truncate">
                    @if(count($shopIds) === 0)
                        {{ __('All shops') }}
                    @else
                        {{ __(':count shops selected', ['count' => count($shopIds)]) }}
                    @endif
                </span>
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
            <span wire:loading wire:target="generate">{{ __('Generating report') }}…</span>
        </x-ui.button>
    </form>

    @if($noShops)
        <x-ui.no-shops />
    @elseif(! $generated)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick the period and shops, then press Generate.')" />
    @elseif(($report['kpi']['all_count'] ?? 0) === 0)
        <x-ui.empty-state :title="__('No receipts')" :description="__('There are no receipts for this period and shops.')" />
    @else
        @php($k = $report['kpi'])
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach([
                [__('Net sales'), $money($k['sale_sum']), $k['sale_count'].' '.__('sale receipts')],
                [__('Average check'), $money($k['avg_check']), $num($k['basket']).' '.__('lines per receipt')],
                [__('Discounts given'), $money($k['discount_sum']), $pct($k['discount_rate']).' '.__('of gross sales')],
                [__('Refunds / cancelled'), $money($k['refund_sum']), $k['refund_count'].' '.__('refunds').' · '.$k['cancelled_count'].' '.__('cancelled')],
            ] as [$label, $value, $hint])
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $value }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Discounts --}}
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Discounts') }}</h2>
        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <x-ui.card padding="p-0">
                <p class="px-4 pt-3 text-sm font-medium text-slate-700">{{ __('Sale receipts by discount depth') }}</p>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr><th class="{{ $th }} text-left">{{ __('Depth') }}</th><th class="{{ $th }} text-right">{{ __('Receipts') }}</th><th class="{{ $th }} text-right">{{ __('Share') }}</th><th class="{{ $th }} text-right">{{ __('Discount') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['discountBuckets'] as $b)
                        <tr><td class="px-3 py-2">{{ __($b['label']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $b['count'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $pct($b['share']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($b['discount']) }}</td></tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
            <x-ui.card padding="p-0">
                <p class="px-4 pt-3 text-sm font-medium text-slate-700">{{ __('Discount by shop') }}</p>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr><th class="{{ $th }} text-left">{{ __('Shop') }}</th><th class="{{ $th }} text-right">{{ __('Discount') }}</th><th class="{{ $th }} text-right">{{ __('Rate') }}</th><th class="{{ $th }} text-right">{{ __('Discounted receipts') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['discountByShop'] as $s)
                        <tr><td class="px-3 py-2">{{ $s['shop'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($s['discount']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $pct($s['rate']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $pct($s['discounted_share']) }}</td></tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
        </div>
        @if($report['discountItems'])
            <x-ui.card padding="p-0" class="mb-8">
                <p class="px-4 pt-3 text-sm font-medium text-slate-700">{{ __('Items with the most discount') }}</p>
                <div class="max-h-96 overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr><th class="{{ $th }} text-left">{{ __('Item') }}</th><th class="{{ $th }} text-right">{{ __('Discount') }}</th><th class="{{ $th }} text-right">{{ __('Rate') }}</th><th class="{{ $th }} text-right">{{ __('Quantity') }}</th><th class="{{ $th }} text-right">{{ __('Receipts') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['discountItems'] as $i)
                        <tr><td class="px-3 py-2">{{ $i['item'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($i['discount']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $pct($i['rate']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $num($i['qty'], 2) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $i['receipts'] }}</td></tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
        @endif

        {{-- Big receipts --}}
        @php($big = $report['big'])
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Big receipts') }}</h2>
        @if($big['cut'] === null)
            <p class="mb-8 text-sm text-slate-500">{{ __('Not enough sale receipts (at least 20) to define big receipts.') }}</p>
        @else
            <p class="mb-3 text-sm text-slate-500">{{ __('Big receipt = the top 5 % of sale receipts by total, i.e. :cut and more.', ['cut' => $money($big['cut'])]) }}</p>
            <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach([
                    [__('Big receipts'), $big['count'], $pct($big['revenue_share']).' '.__('of revenue')],
                    [__('Average big check'), $money($big['avg_check']), __('Other receipts').': '.$money($big['normal_avg_check'])],
                    [__('Discount rate, big'), $pct($big['discount_rate']), __('Other receipts').': '.$pct($big['normal_discount_rate'])],
                ] as [$label, $value, $hint])
                    <x-ui.card><p class="text-xs text-slate-500">{{ $label }}</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ $value }}</p><p class="mt-1 text-xs text-slate-500">{{ $hint }}</p></x-ui.card>
                @endforeach
            </div>
            <div class="mb-6 grid gap-4 lg:grid-cols-2">
                <x-ui.card padding="p-0">
                    <p class="px-4 pt-3 text-sm font-medium text-slate-700">{{ __('Largest receipts') }}</p>
                    <div class="max-h-96 overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                        <thead><tr><th class="{{ $th }} text-left">{{ __('Receipt') }}</th><th class="{{ $th }} text-left">{{ __('Shop') }}</th><th class="{{ $th }} text-right">{{ __('Total') }}</th><th class="{{ $th }} text-right">{{ __('Discount') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                        @foreach($big['top'] as $r)
                            <tr><td class="px-3 py-2"><a href="{{ route('receipts.show', $r['id']) }}" class="text-brand-700 hover:underline">#{{ $r['number'] }}</a>
                                <span class="block text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($r['created_at'])->format('d.m H:i') }} · {{ $r['cashier'] }}</span></td>
                                <td class="px-3 py-2">{{ $r['shop'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($r['total']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($r['discount']) }}</td></tr>
                        @endforeach
                        </tbody></table></div>
                </x-ui.card>
                <x-ui.card padding="p-0">
                    <p class="px-4 pt-3 text-sm font-medium text-slate-700">{{ __('Items in big receipts') }}</p>
                    <p class="px-4 text-xs text-slate-500">{{ __('Index = share of big-receipt revenue / share of all revenue. Above 1 means a big-basket item.') }}</p>
                    <div class="max-h-96 overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                        <thead><tr><th class="{{ $th }} text-left">{{ __('Item') }}</th><th class="{{ $th }} text-right">{{ __('Revenue') }}</th><th class="{{ $th }} text-right">{{ __('Share, big') }}</th><th class="{{ $th }} text-right">{{ __('Index') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                        @foreach($report['bigItems'] as $i)
                            <tr><td class="px-3 py-2">{{ $i['item'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($i['revenue']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $pct($i['big_share']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums {{ ($i['index'] ?? 0) >= 1.5 ? 'font-semibold text-brand-700' : '' }}">{{ $i['index'] === null ? '—' : $num($i['index'], 2) }}</td></tr>
                        @endforeach
                        </tbody></table></div>
                </x-ui.card>
            </div>
        @endif

        {{-- Relations --}}
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Items bought together') }}</h2>
        @if(empty($report['relations']['pairs']))
            <p class="text-sm text-slate-500">{{ __('Not enough data: at least 20 sale receipts and pairs found together in 5 receipts are needed.') }}</p>
        @else
            <p class="mb-3 text-sm text-slate-500">{{ __('Among the :n most frequent items. Lift above 1 means the items are bought together more often than by chance.', ['n' => $report['relations']['items']]) }}</p>
            <x-ui.card padding="p-0">
                <div class="max-h-[32rem] overflow-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead class="sticky top-0 bg-sand-50"><tr><th class="{{ $th }} text-left">{{ __('Item A') }}</th><th class="{{ $th }} text-left">{{ __('Item B') }}</th><th class="{{ $th }} text-right">{{ __('Together') }}</th><th class="{{ $th }} text-right">{{ __('A → B') }}</th><th class="{{ $th }} text-right">{{ __('B → A') }}</th><th class="{{ $th }} text-right">{{ __('Lift') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['relations']['pairs'] as $p)
                        <tr><td class="px-3 py-2">{{ $p['a'] }}</td><td class="px-3 py-2">{{ $p['b'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $p['together'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $pct($p['conf_ab']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $pct($p['conf_ba']) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $num($p['lift'], 2) }}</td></tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
        @endif
    @endif
</div>
