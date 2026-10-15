@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
    $fmt1 = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 1, '.', ' '), '0'), '.');
    $levelBadge = [
        'out' => ['danger', __('Out of stock')],
        'critical' => ['danger', __('< 1 day')],
        'soon' => ['warning', __('< 3 days')],
        'low' => ['warning', __('Low')],
        'ok' => ['success', __('OK')],
        'over' => ['info', __('Overstock')],
    ];
    $confBadge = [
        'none' => ['default', __('Rules only')],
        'low' => ['warning', __('Low confidence')],
        'ok' => ['info', __('Medium confidence')],
        'high' => ['success', __('High confidence')],
    ];
    $th = 'px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500';
    // One severity palette for the KPI tiles, the stacked bars, the heat map and the badges.
    $seg = [
        'out' => 'bg-red-800 text-white',
        'critical' => 'bg-red-600 text-white',
        'soon' => 'bg-amber-500 text-slate-900',
        'low' => 'bg-amber-200 text-amber-900',
    ];
    $segLabel = ['out' => __('Out of stock'), 'critical' => __('< 1 day'), 'soon' => __('< 3 days'), 'low' => __('Low')];
    $heatCls = [
        'out' => 'bg-red-800 text-white',
        'critical' => 'bg-red-600 text-white',
        'short' => 'bg-amber-400 text-slate-900',
        'ok' => 'bg-emerald-200 text-emerald-900',
        'over' => 'bg-blue-200 text-blue-900',
        'na' => 'bg-slate-100 text-slate-500',
    ];
    $coverText = fn ($c) => $c === null ? 'text-slate-400' : ($c < 1 ? 'font-semibold text-red-700' : ($c < 3 ? 'font-semibold text-amber-700' : 'text-slate-600'));
    $chev = 'h-4 w-4 shrink-0 transition-transform motion-reduce:transition-none';
@endphp
<div>
    <x-ui.page-header :title="__('Ordering')" :subtitle="__('Sales forecast and a prepared order to the main warehouse.')" />

    {{-- Controls: horizon as a segmented 1-7 control, refresh, bulk draft --}}
    <div class="mb-4 flex flex-wrap items-end gap-x-4 gap-y-3">
        <div>
            <span id="ordering-horizon-label" class="mb-1 block text-xs font-medium text-slate-500">{{ __('Cover, days') }}</span>
            <div role="group" aria-labelledby="ordering-horizon-label" class="inline-flex overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
                @foreach($horizons as $h)
                    <button type="button" wire:click="setHorizon({{ $h }})" aria-pressed="{{ $h === $horizon ? 'true' : 'false' }}"
                            class="min-w-9 border-l border-slate-200 px-3 py-2 text-sm font-medium tabular-nums transition first:border-l-0 focus:z-10 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-500 {{ $h === $horizon ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-sand-50' }}">{{ $h }}</button>
                @endforeach
            </div>
            @if($custom)
                <button type="button" wire:click="useShopRules" class="mt-1 text-xs font-medium text-brand-700 hover:underline" data-use-rules>{{ __('Use the setting of each shop') }}</button>
            @else
                <span class="mt-1 block text-xs text-slate-500" data-rules-note>{{ __('Each shop uses the days set in Order rules.') }}</span>
            @endif
        </div>
        <x-ui.button type="button" variant="secondary" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">{{ __('Refresh') }}</x-ui.button>
        @if($kpi['lines'] > 0)
            <x-ui.button type="button" wire:click="prepareAllOrders" wire:loading.attr="disabled" wire:target="prepareOrder,prepareAllOrders"
                         wire:confirm="{{ __('Prepare a separate draft for every shop?') }}">{{ __('Prepare orders for all shops') }} ({{ $kpi['shops'] }})</x-ui.button>
        @endif
        @if($generated_at)
            <p class="pb-2 text-sm text-slate-500">{{ __('Updated') }} {{ $generated_at }}</p>
        @endif
    </div>

    @if($notice)
        <div class="mb-4 space-y-1 rounded-lg border border-slate-200 bg-white p-3 text-sm" data-notice>
            @foreach($notice['created'] as $c)
                <p class="text-brand-700">{{ __('Draft #:id created for :shop (:count items).', ['id' => $c['order_id'], 'shop' => $c['shop'], 'count' => $c['lines']]) }}</p>
            @endforeach
            @foreach($notice['updated'] as $u)
                <p class="text-brand-700">{{ __('Draft #:id of :shop updated: :count new items added.', ['id' => $u['order_id'], 'shop' => $u['shop'], 'count' => $u['lines']]) }}</p>
            @endforeach
            @foreach($notice['skipped'] as $s)
                <p class="text-amber-700">
                    @if($s['reason'] === 'forbidden')
                        {{ __(':shop: you are not allowed to create orders.', ['shop' => $s['shop']]) }}
                    @else
                        {{ __(':shop: nothing to order.', ['shop' => $s['shop']]) }}
                    @endif
                </p>
            @endforeach
        </div>
    @endif

    @if($noShops)
        <x-ui.no-shops />
    @elseif($shops->isEmpty())
        <x-ui.empty-state :title="__('No shops yet')" :description="__('Shops will appear here once 1C sends stock data for them.')" />
    @else
        @unless($warehouseConfigured)
            <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-no-warehouse>{{ __('The main warehouse is not set. An admin can choose it in Settings > Warehouse.') }} {{ __('Needs are shown without checking the warehouse stock.') }}</p>
        @endunless

        {{-- 1. KPI strip --}}
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" data-kpi>
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <p class="text-xs font-medium text-slate-500">{{ __('Shops to order') }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ $kpi['shops'] }}<span class="text-sm font-medium text-slate-400"> / {{ $shops->count() }}</span></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <p class="text-xs font-medium text-slate-500">{{ __('Lines to order') }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-brand-700">{{ $kpi['lines'] }}</p>
                <p class="text-xs text-slate-400">{{ __(':count items', ['count' => $kpi['items']]) }}</p>
            </div>
            @foreach(['out', 'critical', 'soon'] as $lv)
                <div class="rounded-xl border p-3 shadow-sm {{ $kpi[$lv] > 0 ? ($lv === 'soon' ? 'border-amber-300 bg-amber-50' : 'border-red-200 bg-red-50') : 'border-slate-200 bg-white' }}">
                    <p class="flex items-center gap-1.5 text-xs font-medium {{ $kpi[$lv] > 0 ? ($lv === 'soon' ? 'text-amber-800' : 'text-red-800') : 'text-slate-500' }}">
                        <span class="inline-block h-2.5 w-2.5 rounded-sm {{ explode(' ', $seg[$lv])[0] }}" aria-hidden="true"></span>{{ $segLabel[$lv] }}
                    </p>
                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $kpi[$lv] > 0 ? ($lv === 'soon' ? 'text-amber-800' : 'text-red-800') : 'text-slate-400' }}">{{ $kpi[$lv] }}</p>
                </div>
            @endforeach
            <div class="rounded-xl border p-3 shadow-sm {{ $warehouseConfigured && $kpi['short_items'] > 0 ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white' }}">
                <p class="text-xs font-medium {{ $warehouseConfigured && $kpi['short_items'] > 0 ? 'text-red-800' : 'text-slate-500' }}">{{ __('Shortages vs warehouse') }}</p>
                @if($warehouseConfigured)
                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $kpi['short_items'] > 0 ? 'text-red-800' : 'text-slate-400' }}">{{ $kpi['short_items'] }}</p>
                    <p class="text-xs {{ $kpi['starved'] > 0 ? 'font-medium text-red-700' : 'text-slate-400' }}">{{ __('Starved') }}: {{ $kpi['starved'] }}</p>
                @else
                    <p class="mt-1 text-2xl font-bold text-slate-300">—</p>
                @endif
            </div>
        </div>

        @if($kpi['lines'] > 0 || $heat)
            <div class="mb-4 grid grid-cols-1 items-start gap-4">
                {{-- 2. Stacked bars of urgency levels per shop --}}
                <x-ui.card padding="p-4" class="min-w-0" data-levels>
                    <h2 class="text-sm font-semibold text-slate-800">{{ __('Lines to order by urgency') }}</h2>
                    <p class="mb-3 text-xs text-slate-500">{{ __('Click a shop to open its list.') }}</p>
                    @php $maxCount = max(1, $shops->max('count')); @endphp
                    <ul class="space-y-1.5">
                        @foreach($shops as $shop)
                            <li wire:key="ord-lv-{{ $shop['id'] }}">
                                <button type="button" wire:click="selectShop({{ $shop['id'] }})" title="{{ $shop['name'] }}"
                                        class="flex w-full items-center gap-3 rounded-lg px-1.5 py-1 text-left transition hover:bg-sand-50 focus:outline-none focus:ring-2 focus:ring-brand-500 {{ $tab === 'shops' && $active && $active['id'] === $shop['id'] ? 'bg-brand-50' : '' }}">
                                    <span class="w-24 shrink-0 truncate text-sm text-slate-700 sm:w-32">{{ $shop['name'] }}</span>
                                    <span class="flex h-6 min-w-0 flex-1 items-center">
                                        @if($shop['count'] === 0)
                                            <span class="text-xs text-slate-400">{{ __('Nothing to order') }}</span>
                                        @else
                                            <span class="flex h-6 overflow-hidden rounded" style="width: {{ round(100 * $shop['count'] / $maxCount, 1) }}%">
                                                @foreach(['out', 'critical', 'soon', 'low'] as $lv)
                                                    @if($shop['counts'][$lv] > 0)
                                                        <span class="flex items-center justify-center text-xs font-semibold tabular-nums {{ $seg[$lv] }}" style="flex: {{ $shop['counts'][$lv] }} 1 0; min-width: 1.5rem;" title="{{ $segLabel[$lv] }}: {{ $shop['counts'][$lv] }}">{{ $shop['counts'][$lv] }}</span>
                                                    @endif
                                                @endforeach
                                            </span>
                                        @endif
                                    </span>
                                    <span class="w-8 shrink-0 text-right text-sm font-semibold tabular-nums text-slate-700">{{ $shop['count'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="{{ __('Legend') }}">
                        @foreach(['out', 'critical', 'soon', 'low'] as $lv)
                            <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm {{ explode(' ', $seg[$lv])[0] }}" aria-hidden="true"></span>{{ $segLabel[$lv] }}</li>
                        @endforeach
                    </ul>
                </x-ui.card>

                {{-- 3. Cover-days heat map: top items x shops --}}
                @if($heat)
                    <x-ui.card padding="p-4" class="min-w-0" data-heat>
                        <h2 class="text-sm font-semibold text-slate-800">{{ __('Days of stock left') }}</h2>
                        <p class="mb-3 text-xs text-slate-500">{{ __('The :count most important items (ABC class and urgency). Click a cell to open the shop.', ['count' => count($heat['items'])]) }}</p>
                        <div class="max-h-96 overflow-auto">
                            <table class="w-full border-separate border-spacing-1 text-xs">
                                <thead>
                                    <tr>
                                        <th class="sticky left-0 top-0 z-20 bg-white pr-2 text-left font-medium text-slate-500">{{ __('Item') }}</th>
                                        @foreach($heat['shops'] as $hs)
                                            <th class="sticky top-0 z-10 bg-white px-1 pb-1 text-center font-medium text-slate-600"><span class="block max-w-20 truncate" title="{{ $hs['name'] }}">{{ $hs['name'] }}</span></th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($heat['items'] as $hi)
                                        <tr>
                                            <th scope="row" class="sticky left-0 z-10 bg-white pr-2 text-left font-normal text-slate-700"><span class="block max-w-32 truncate sm:max-w-44" title="{{ $hi['name'] }}">{{ $hi['name'] }}</span></th>
                                            @foreach($heat['shops'] as $hs)
                                                @php
                                                    $c = $hs['cells'][$hi['id']];
                                                    $k = $c === null ? null : ($c['level'] === 'out' ? 'out' : ($c['level'] === 'over' ? 'over' : ($c['cover'] === null ? 'na' : ($c['cover'] < 1 ? 'critical' : ($c['cover'] < $horizon ? 'short' : 'ok')))));
                                                    $txt = $c === null ? '' : ($k === 'out' ? '0' : ($c['cover'] === null ? '·' : ($c['cover'] > 99 ? '99+' : $fmt1($c['cover']))));
                                                    $tip = $c === null ? $hi['name'].' · '.$hs['name'].': '.__('Not tracked')
                                                        : $hi['name'].' · '.$hs['name'].': '.__('Cover, days').' '.($c['cover'] === null ? '—' : $fmt1($c['cover'])).', '.__('Estimated').' '.$fmt($c['estimated']).', '.__('Min').' '.$fmt($c['min']).', '.__('Per day').' '.$fmt($c['speed']);
                                                @endphp
                                                <td class="p-0">
                                                    @if($c === null)
                                                        <span class="block rounded border border-dashed border-slate-200 py-1.5 text-center text-slate-300" title="{{ $tip }}">–</span>
                                                    @else
                                                        <button type="button" wire:click="selectShop({{ $hs['id'] }})" title="{{ $tip }}" aria-label="{{ $tip }}"
                                                                class="block w-full min-w-12 rounded py-1.5 text-center font-semibold tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-500 {{ $heatCls[$k] }}">{{ $txt }}</button>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="{{ __('Legend') }}">
                            <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-red-800" aria-hidden="true"></span>{{ __('Out of stock') }} (0)</li>
                            <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-red-600" aria-hidden="true"></span>{{ __('< 1 day') }}</li>
                            <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-amber-400" aria-hidden="true"></span>{{ __('Under :days days', ['days' => $horizon]) }}</li>
                            <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-emerald-200" aria-hidden="true"></span>{{ __('Enough') }}</li>
                            <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-blue-200" aria-hidden="true"></span>{{ __('Overstock') }}</li>
                            <li class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-slate-100 ring-1 ring-slate-300" aria-hidden="true"></span>{{ __('No sales speed') }} (·)</li>
                        </ul>
                    </x-ui.card>
                @endif
            </div>
        @endif

        {{-- One tab per shop plus the warehouse pick list --}}
        <div class="mb-4 overflow-x-auto border-b border-slate-200">
            <nav class="-mb-px flex gap-1" role="tablist">
                @foreach($shops as $shop)
                    @php $on = $tab === 'shops' && $shop['id'] === $active['id']; @endphp
                    <button type="button" role="tab" wire:key="ord-tab-{{ $shop['id'] }}" wire:click="selectShop({{ $shop['id'] }})"
                            aria-selected="{{ $on ? 'true' : 'false' }}"
                            class="flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition {{ $on ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                        {{ $shop['name'] }}
                        <x-ui.badge :variant="$shop['out'] > 0 ? 'danger' : ($shop['count'] > 0 ? 'warning' : 'default')">{{ $shop['count'] }}</x-ui.badge>
                    </button>
                @endforeach
                <button type="button" role="tab" wire:key="ord-tab-pick" wire:click="showTab('pick')" aria-selected="{{ $tab === 'pick' ? 'true' : 'false' }}"
                        class="flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition {{ $tab === 'pick' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                    {{ __('Warehouse pick list') }}
                    <x-ui.badge :variant="$kpi['short_items'] > 0 ? 'danger' : 'default'">{{ $kpi['items'] }}</x-ui.badge>
                </button>
            </nav>
        </div>

        @if($tab === 'pick')
            {{-- 5. Warehouse pick list: all shops together, per item --}}
            <div class="mb-3 flex flex-wrap items-center gap-3">
                @if($warehouseConfigured && $warehouseName)<p class="text-sm text-slate-500">{{ __('Warehouse') }}: {{ $warehouseName }}</p>@endif
                @if($kpi['short_items'] > 0)<x-ui.badge variant="danger">{{ __('Shortages vs warehouse') }}: {{ $kpi['short_items'] }}</x-ui.badge>@endif
                @if($kpi['starved'] > 0)<x-ui.badge variant="danger">{{ __('Out-of-stock shops getting nothing') }}: {{ $kpi['starved'] }}</x-ui.badge>@endif
                <ul class="ml-auto flex flex-wrap gap-x-4 text-xs text-slate-600" aria-label="{{ __('Legend') }}">
                    <li class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded-sm bg-brand-600" aria-hidden="true"></span>{{ __('Send') }}</li>
                    <li class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded-sm bg-red-600" aria-hidden="true"></span>{{ __('Short') }}</li>
                    <li class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded-sm bg-slate-400" aria-hidden="true"></span>{{ __('Warehouse has') }}</li>
                </ul>
            </div>
            @if(empty($pick))
                <x-ui.empty-state :title="__('Nothing to pick')" :description="__('No shop needs a delivery for the chosen days.')" />
            @else
                @php
                    $pickBar = function ($item) {
                        $scale = max($item['need'], $item['available'] ?? 0, 0.0001);
                        return [
                            'send' => round(100 * $item['send'] / $scale, 1),
                            'short' => round(100 * max($item['need'] - $item['send'], 0) / $scale, 1),
                            'avail' => $item['available'] === null ? null : round(100 * $item['available'] / $scale, 1),
                        ];
                    };
                @endphp
                {{-- Mobile cards --}}
                <div class="max-h-[70vh] space-y-3 overflow-y-auto md:hidden">
                    @foreach($pick as $item)
                        @php $b = $pickBar($item); @endphp
                        <x-ui.card wire:key="ord-pickc-{{ $item['item_id'] }}">
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-medium text-slate-900">{{ $item['item'] }}</p>
                                <span class="flex shrink-0 flex-wrap justify-end gap-1">
                                    <x-ui.badge :variant="$levelBadge[$item['urgency']][0]">{{ $levelBadge[$item['urgency']][1] }}</x-ui.badge>
                                    @if($item['starved'])<x-ui.badge variant="danger">{{ __('Starved') }}</x-ui.badge>@endif
                                </span>
                            </div>
                            <div class="mt-2 space-y-1" role="img" aria-label="{{ __('Need') }} {{ $fmt($item['need']) }}, {{ __('Warehouse has') }} {{ $item['available'] === null ? '—' : $fmt($item['available']) }}, {{ __('Send') }} {{ $fmt($item['send']) }}">
                                <div class="flex h-2.5 overflow-hidden rounded bg-slate-100"><span class="bg-brand-600" style="width: {{ $b['send'] }}%"></span><span class="bg-red-600" style="width: {{ $b['short'] }}%"></span></div>
                                @if($b['avail'] !== null)<div class="h-2.5 overflow-hidden rounded bg-slate-100"><span class="block h-full bg-slate-400" style="width: {{ $b['avail'] }}%"></span></div>@endif
                            </div>
                            <p class="mt-1 text-xs text-slate-600">{{ __('Need') }} <b class="tabular-nums">{{ $fmt($item['need']) }}</b> · {{ __('Warehouse has') }} <b class="tabular-nums">{{ $item['available'] === null ? '—' : $fmt($item['available']) }}</b> · {{ __('Send') }} <b class="tabular-nums text-brand-700">{{ $fmt($item['send']) }}</b>@if($item['short'] > 0) · <b class="tabular-nums text-red-700">▲ {{ __('Short') }} {{ $fmt($item['short']) }} ({{ $fmt1($item['short_pct']) }}%)</b>@endif</p>
                            <p class="mt-2 flex flex-wrap gap-1">
                                @foreach($item['shops'] as $s)
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $s['starved'] ? 'bg-red-100 font-semibold text-red-700' : 'bg-slate-100 text-slate-700' }}">{{ $s['shop'] }}: {{ $fmt($s['send']) }}@if($s['short'] > 0) / {{ $fmt($s['need']) }}@endif</span>
                                @endforeach
                            </p>
                        </x-ui.card>
                    @endforeach
                </div>

                {{-- Desktop table --}}
                <x-ui.card padding="p-0" class="hidden md:block">
                    <div class="max-h-[65vh] overflow-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="sticky top-0 z-10 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                                <tr>
                                    <th class="{{ $th }} text-left">{{ __('Item') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Need') }} / {{ __('Warehouse has') }}</th>
                                    <th class="{{ $th }} text-right">{{ __('Send') }}</th>
                                    <th class="{{ $th }} text-right">{{ __('Short') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Per shop') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($pick as $item)
                                    @php $b = $pickBar($item); @endphp
                                    <tr wire:key="ord-pick-{{ $item['item_id'] }}" class="align-top">
                                        <td class="px-3 py-3 text-sm font-medium text-slate-900">
                                            {{ $item['item'] }}
                                            <span class="mt-1 flex flex-wrap gap-1">
                                                <x-ui.badge :variant="$levelBadge[$item['urgency']][0]">{{ $levelBadge[$item['urgency']][1] }}</x-ui.badge>
                                                @if($item['starved'])<x-ui.badge variant="danger">{{ __('Starved') }}</x-ui.badge>@endif
                                            </span>
                                        </td>
                                        <td class="w-64 px-3 py-3 text-xs text-slate-600">
                                            <div class="space-y-1" role="img" aria-label="{{ __('Need') }} {{ $fmt($item['need']) }}, {{ __('Warehouse has') }} {{ $item['available'] === null ? '—' : $fmt($item['available']) }}">
                                                <div class="flex items-center gap-2"><div class="flex h-2.5 flex-1 overflow-hidden rounded bg-slate-100"><span class="bg-brand-600" style="width: {{ $b['send'] }}%"></span><span class="bg-red-600" style="width: {{ $b['short'] }}%"></span></div><span class="w-12 text-right tabular-nums">{{ $fmt($item['need']) }}</span></div>
                                                @if($b['avail'] !== null)<div class="flex items-center gap-2"><div class="h-2.5 flex-1 overflow-hidden rounded bg-slate-100"><span class="block h-full bg-slate-400" style="width: {{ $b['avail'] }}%"></span></div><span class="w-12 text-right tabular-nums text-slate-500">{{ $fmt($item['available']) }}</span></div>@endif
                                            </div>
                                        </td>
                                        <td class="px-3 py-3 text-right text-sm font-bold tabular-nums text-brand-700">{{ $fmt($item['send']) }}</td>
                                        <td class="px-3 py-3 text-right text-sm tabular-nums {{ $item['short'] > 0 ? 'font-semibold text-red-700' : 'text-slate-400' }}">{{ $item['short'] > 0 ? '▲ '.$fmt($item['short']).' ('.$fmt1($item['short_pct']).'%)' : '—' }}</td>
                                        <td class="px-3 py-3 text-xs">
                                            @foreach($item['shops'] as $s)
                                                <span class="mb-1 mr-1 inline-block whitespace-nowrap rounded-full px-2 py-0.5 {{ $s['starved'] ? 'bg-red-100 font-semibold text-red-700' : 'bg-slate-100 text-slate-700' }}">{{ $s['shop'] }}: {{ $fmt($s['send']) }}@if($s['short'] > 0) / {{ $fmt($s['need']) }}@endif</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif
        @elseif(! $active || $active['count'] === 0)
            <x-ui.empty-state :title="__('Nothing to order')" :description="__('Every item of this shop is covered for the chosen days.')" />
        @else
            {{-- 6. Shop header with the primary action --}}
            <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="min-w-0">
                    <h2 class="truncate text-base font-semibold text-slate-900">{{ $active['name'] }}</h2>
                    <p class="text-xs text-slate-500">{{ __(':count items to order', ['count' => $active['count']]) }}@if($active['short'] > 0) · <span class="font-medium text-red-700">{{ __('Shortages vs warehouse') }}: {{ $active['short'] }}</span>@endif</p>
                </div>
                <div class="flex flex-wrap gap-1">
                    @foreach(['out', 'critical', 'soon', 'low'] as $lv)
                        @if($active['counts'][$lv] > 0)<span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $seg[$lv] }}">{{ $segLabel[$lv] }}: {{ $active['counts'][$lv] }}</span>@endif
                    @endforeach
                </div>
                <div class="ml-auto flex flex-wrap items-center gap-3">
                    @if(isset($drafts[$active['id']]))
                        <a href="{{ route('orders.show', $drafts[$active['id']]) }}" class="text-sm font-medium text-brand-700 hover:underline">{{ __('Open the draft') }} #{{ $drafts[$active['id']] }}</a>
                    @endif
                    <x-ui.button type="button" wire:click="prepareOrder({{ $active['id'] }})" wire:loading.attr="disabled" wire:target="prepareOrder,prepareAllOrders">{{ __('Prepare order') }} ({{ $active['count'] }})</x-ui.button>
                </div>
                <p class="w-full text-xs text-slate-500">{{ __('The draft is edited and confirmed by the shop under Orders.') }}</p>
            </div>

            {{-- Mobile card list --}}
            <div class="max-h-[75vh] space-y-3 overflow-y-auto md:hidden">
                @foreach($active['rows'] as $row)
                    @php $isOpen = $openItem === $row['item_id']; @endphp
                    <x-ui.card wire:key="ord-card-{{ $active['id'] }}-{{ $row['item_id'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                @if($row['group'])<p class="text-xs text-slate-500">{{ $row['group'] }}</p>@endif
                                <p class="font-medium text-slate-900">{{ $row['item'] }}@if($row['stale'])<span class="ml-1 text-xs text-amber-600" title="{{ __('Old stock snapshot') }}">⚠</span>@endif</p>
                                <p class="mt-1 flex gap-1"><span class="rounded bg-brand-100 px-1.5 text-xs font-semibold text-brand-800">{{ $row['abc'] }}</span>@if($row['xyz'])<span class="rounded border border-slate-300 px-1.5 text-xs font-semibold text-slate-600">{{ $row['xyz'] }}</span>@endif</p>
                            </div>
                            <x-ui.badge :variant="$levelBadge[$row['level']][0]">{{ $levelBadge[$row['level']][1] }}</x-ui.badge>
                        </div>
                        <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                            <div><dt class="text-xs text-slate-500">{{ __('Stock') }}</dt><dd class="tabular-nums">{{ $fmt($row['stock']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Estimated') }}</dt><dd class="font-semibold tabular-nums">{{ $fmt($row['estimated']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Min') }}</dt><dd class="tabular-nums">{{ $fmt($row['min']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Per day') }}</dt><dd class="tabular-nums">{{ $fmt($row['speed']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Cover, days') }}</dt><dd class="tabular-nums {{ $coverText($row['cover']) }}">{{ $fmt1($row['cover']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Order') }}</dt><dd class="text-base font-bold tabular-nums text-brand-700">{{ $fmt($warehouseConfigured ? $row['send'] : $row['need']) }}</dd></div>
                        </dl>
                        @if($row['short'] > 0)<p class="mt-1 text-xs font-medium text-red-700">▲ {{ __('needs') }} {{ $fmt($row['need']) }}</p>@endif
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <x-ui.badge :variant="$confBadge[$row['confidence']][0]">{{ $confBadge[$row['confidence']][1] }}</x-ui.badge>
                            <button type="button" wire:click="toggleItem({{ $row['item_id'] }})" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
                                {{ $isOpen ? __('Hide forecast') : __('Show forecast') }}
                                <svg class="{{ $chev }} {{ $isOpen ? 'rotate-180' : '' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                            </button>
                        </div>
                        @if($isOpen && $detail)
                            <div class="mt-3 border-t border-slate-100 pt-3">@include('livewire.ordering.partials.detail')</div>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <x-ui.card padding="p-0" class="hidden md:block">
                <div class="max-h-[70vh] overflow-auto">
                    <table class="min-w-full">
                        <thead class="sticky top-0 z-10 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Item') }}</th>
                                <th class="{{ $th }} text-center">{{ __('ABC/XYZ') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Stock') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Estimated') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Min') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Per day') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Cover, days') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Stock-out date') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Order') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Confidence') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Status') }}</th>
                                <th class="{{ $th }}"><span class="sr-only">{{ __('Show forecast') }}</span></th>
                            </tr>
                        </thead>
                        @foreach($active['rows'] as $row)
                            @php $isOpen = $openItem === $row['item_id']; @endphp
                            <tbody wire:key="ord-row-{{ $active['id'] }}-{{ $row['item_id'] }}" class="border-b border-slate-100">
                                <tr class="cursor-pointer transition hover:bg-sand-50 {{ $isOpen ? 'bg-sand-50' : '' }}" wire:click="toggleItem({{ $row['item_id'] }})">
                                    <td class="px-3 py-2.5 text-sm font-medium text-slate-900">{{ $row['item'] }}@if($row['stale'])<span class="ml-1 text-xs text-amber-600" title="{{ __('Old stock snapshot') }}">⚠</span>@endif
                                        @if($row['group'])<span class="block text-xs font-normal text-slate-400">{{ $row['group'] }}</span>@endif</td>
                                    <td class="px-3 py-2.5 text-center"><span class="rounded bg-brand-100 px-1.5 text-xs font-semibold text-brand-800">{{ $row['abc'] }}</span>@if($row['xyz'])<span class="ml-1 rounded border border-slate-300 px-1.5 text-xs font-semibold text-slate-600">{{ $row['xyz'] }}</span>@endif</td>
                                    <td class="px-3 py-2.5 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['stock']) }}</td>
                                    <td class="px-3 py-2.5 text-right text-sm font-semibold tabular-nums text-slate-700">{{ $fmt($row['estimated']) }}@if($row['in_transit'] > 0)<span class="block text-xs font-normal text-slate-400">+{{ $fmt($row['in_transit']) }} {{ __('in transit') }}</span>@endif</td>
                                    <td class="px-3 py-2.5 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['min']) }}</td>
                                    <td class="px-3 py-2.5 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['speed']) }}</td>
                                    <td class="px-3 py-2.5 text-right text-sm tabular-nums {{ $coverText($row['cover']) }}">{{ $fmt1($row['cover']) }}</td>
                                    <td class="px-3 py-2.5 text-sm text-slate-500">{{ $row['stockout_date'] ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-right text-sm font-bold tabular-nums text-brand-700">
                                        {{ $fmt($warehouseConfigured ? $row['send'] : $row['need']) }}
                                        @if($row['short'] > 0)<span class="block text-xs font-normal text-red-700">▲ {{ __('needs') }} {{ $fmt($row['need']) }}</span>@endif
                                    </td>
                                    <td class="px-3 py-2.5 text-sm"><x-ui.badge :variant="$confBadge[$row['confidence']][0]">{{ $confBadge[$row['confidence']][1] }}</x-ui.badge></td>
                                    <td class="px-3 py-2.5 text-sm"><x-ui.badge :variant="$levelBadge[$row['level']][0]">{{ $levelBadge[$row['level']][1] }}</x-ui.badge></td>
                                    <td class="px-2 py-2.5 text-slate-400">
                                        <button type="button" wire:click.stop="toggleItem({{ $row['item_id'] }})" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-label="{{ $isOpen ? __('Hide forecast') : __('Show forecast') }}" class="rounded p-1 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-500">
                                            <svg class="{{ $chev }} {{ $isOpen ? 'rotate-180' : '' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                                        </button>
                                    </td>
                                </tr>
                                @if($isOpen && $detail)
                                    <tr><td colspan="12" class="bg-white px-4 py-4">@include('livewire.ordering.partials.detail')</td></tr>
                                @endif
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- Shown only while Refresh recalculates; closes by itself. Hidden explicitly because Livewire's
         stylesheet does not hide combined wire:loading modifiers before the first request. --}}
    <div wire:loading.flex.delay.shorter wire:target="refresh" style="display: none;"
         class="fixed inset-0 z-50 items-center justify-center px-4 py-6"
         role="alertdialog" aria-modal="true" aria-labelledby="ordering-title" aria-describedby="ordering-text">
        <div class="modal-backdrop fixed inset-0 bg-slate-900/50"></div>
        <div class="modal-panel relative w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-xl">
            <svg class="spinner mx-auto h-12 w-12 text-brand-700" viewBox="0 0 50 50" fill="none" aria-hidden="true">
                <circle cx="25" cy="25" r="20" stroke="currentColor" stroke-opacity="0.2" stroke-width="5"></circle>
                <path d="M45 25a20 20 0 0 0-20-20" stroke="currentColor" stroke-width="5" stroke-linecap="round"></path>
            </svg>
            <h2 id="ordering-title" class="mt-4 text-lg font-semibold text-slate-900">{{ __('Calculating orders…') }}</h2>
            <p id="ordering-text" class="mt-1 text-sm text-slate-500">{{ __('Checking stock, min/max rules and sales for every shop. This can take a few seconds.') }}</p>
            <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                <div class="indeterminate-bar h-full w-1/3 rounded-full bg-brand-600"></div>
            </div>
        </div>
    </div>
</div>
