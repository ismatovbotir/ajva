<div>
    <x-ui.page-header :title="__('Dashboard')" :subtitle="__('Sales, profit and stock health across all shops.')" />

    <div class="space-y-6">
        <livewire:sales-board />

        {{-- Section: Stock health --}}
        <section class="space-y-4" aria-labelledby="sec-stock-health">
            <div>
                <h2 id="sec-stock-health" class="text-lg font-semibold text-slate-900">{{ __('Stock health') }}</h2>
                <p class="text-sm text-slate-500">{{ __('On-hand stock from the latest 1C sync, valued at cost price.') }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Stock value at cost') }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900">{{ $health['value_label'] }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __(':units units on hand', ['units' => $health['units_label']]) }}</p>
                    @if($health['missing_items'] > 0)
                        <p class="mt-2 rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-800">
                            {{ __('Cost missing for :count items — :percent% of units are not valued.', ['count' => $health['missing_items'], 'percent' => $health['uncovered_percent']]) }}
                        </p>
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Below minimum') }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums {{ $exceptions['total'] > 0 ? 'text-red-700' : 'text-slate-900' }}">{{ $exceptions['total'] }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Item and shop pairs at or below their minimum.') }}</p>
                    @if(Route::has('items.index'))
                        <a href="{{ route('items.index') }}" class="mt-2 inline-block text-sm font-medium text-brand-700 hover:underline">{{ __('Open items list') }} →</a>
                    @endif
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Out of stock') }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums {{ $health['out_of_stock'] > 0 ? 'text-red-700' : 'text-slate-900' }}">{{ $health['out_of_stock'] }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Item and shop pairs with a minimum rule and zero stock.') }}</p>
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Reorder-rule coverage') }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900">{{ number_format($coverage['percent'], 1) }}%</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __(':covered of :total stock records have a rule.', ['covered' => $coverage['covered'], 'total' => $coverage['total']]) }}</p>
                </x-ui.card>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <x-ui.card class="lg:col-span-1">
                    <h3 class="text-base font-semibold text-slate-900">{{ __('Stock value by shop') }}</h3>
                    <p class="mb-4 text-sm text-slate-500">{{ __('At cost price; items without a cost are not valued.') }}</p>
                    @if(empty($health['shops']))
                        <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
                    @else
                        <ul class="max-h-80 space-y-3 overflow-y-auto pr-1">
                            @foreach($health['shops'] as $s)
                                <li>
                                    <div class="flex items-baseline justify-between gap-2 text-sm">
                                        <span class="min-w-0 truncate font-medium text-slate-900">{{ $s['name'] }}</span>
                                        <span class="shrink-0 tabular-nums text-slate-600">{{ $s['label'] }}</span>
                                    </div>
                                    <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full" style="width: {{ max($s['percent'], 1) }}%; background-color: {{ $barColor }};"></div></div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
                <div class="lg:col-span-2">
            <x-ui.card class="h-full">
                <div class="mb-4">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('Reorder risk') }}</h2>
                    <p class="text-sm text-slate-500">{{ __('Items at or below their minimum threshold, most urgent first.') }}</p>
                </div>

                @if(empty($exceptions['rows']))
                    <x-ui.empty-state :title="__('No reorder risks right now')" :description="__('No (item, shop) pair with a configured rule is currently at or below its minimum.')" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Min') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Severity') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($exceptions['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['item_name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['shop_name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['qty_label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['min_label'] }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="inline-flex items-center gap-1.5 font-medium" style="color: {{ $row['severity_color'] }};">
                                                <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $row['severity_color'] }};"></span>
                                                {{ $row['severity_label'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($exceptions['hidden'] > 0)
                        <p class="mt-3 text-xs text-slate-500">{{ __(':count more not shown', ['count' => $exceptions['hidden']]) }}</p>
                    @endif
                @endif
            </x-ui.card>
                </div>
            </div>
        </section>

        {{-- Section: Pricing health --}}
        <section class="space-y-4" aria-labelledby="sec-pricing">
            <div>
                <h2 id="sec-pricing" class="text-lg font-semibold text-slate-900">{{ __('Pricing health') }}</h2>
                <p class="text-sm text-slate-500">{{ __('Cost vs. sell price consistency and missing prices.') }}</p>
            </div>
        {{-- View 5: Price integrity / margin — best/worst margin % ranked by item --}}
        <x-ui.card>
            <div x-data="{ tab: 'best' }">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold text-slate-900">
                            {{ __('Margin by item') }}
                            @if($belowCost['count'] > 0)
                                <button type="button" @click="tab = 'below'" class="focus:outline-none">
                                    <x-ui.badge variant="danger">{{ __('Below cost') }}: {{ $belowCost['count'] }}</x-ui.badge>
                                </button>
                            @endif
                        </h2>
                        <p class="text-sm text-slate-500">{{ __('Cost vs. sell price margin, based on the latest 1C sync.') }}</p>
                    </div>
                    <div class="inline-flex shrink-0 items-center gap-0.5 rounded-lg border border-slate-300 bg-white p-0.5">
                        <button type="button" @click="tab = 'best'" :class="tab === 'best' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Best margins') }}</button>
                        <button type="button" @click="tab = 'worst'" :class="tab === 'worst' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Worst margins') }}</button>
                        <button type="button" @click="tab = 'below'" :class="tab === 'below' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Below cost') }} ({{ $belowCost['count'] }})</button>
                    </div>
                </div>

                <div class="mb-4 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                    <span>{{ __('Items missing a cost price') }}: <strong class="text-slate-900">{{ $margins['missing_cost'] }}</strong></span>
                    <span>{{ __('Items missing a sell price') }}: <strong class="text-slate-900">{{ $margins['missing_sell'] }}</strong></span>
                    <span>{{ __('Items missing both prices') }}: <strong class="text-slate-900">{{ $margins['missing_both'] }}</strong></span>
                </div>

                {{-- Selling price below cost: every case, scrollable --}}
                <div x-show="tab === 'below'" x-cloak>
                    <p class="mb-3 text-sm text-slate-500">{{ __('A selling price lower than the cost price — an intentional discount or a data mistake. Worth checking.') }}</p>
                    @if($belowCost['count'] === 0)
                        <x-ui.empty-state :title="__('No prices below cost')" :description="__('Every selling price is at or above its cost price.')" />
                    @else
                        <div class="max-h-[60vh] overflow-auto rounded-lg border border-slate-200">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="sticky top-0 z-10 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Price type') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Cost price') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Sell price') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Difference') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">%</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($belowCost['rows'] as $row)
                                        <tr>
                                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                                {{ $row['item_name'] }}
                                                <x-ui.badge variant="danger" class="ml-1">{{ __('Below cost') }}</x-ui.badge>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-slate-500">{{ $row['group_name'] }}</td>
                                            <td class="px-4 py-3 text-sm text-slate-500">{{ $row['price_name'] }}</td>
                                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $row['cost_label'] }}</td>
                                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-700">{{ $row['sell_label'] }}</td>
                                            <td class="px-4 py-3 text-right text-sm font-medium tabular-nums text-red-700">{{ $row['diff_label'] }}</td>
                                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums text-red-700">{{ $row['pct_label'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if(($belowCost['hidden'] ?? 0) > 0)
                            <p class="mt-3 text-xs text-slate-500">{{ __(':count more not shown', ['count' => $belowCost['hidden']]) }}</p>
                        @endif
                    @endif
                </div>

                <div x-show="tab !== 'below'">
                @if($margins['total_with_margin'] === 0)
                    <x-ui.empty-state :title="__('No items with both cost and sell prices yet')" :description="__('Margin can only be computed once an item has both a cost and a sell price synced from 1C.')" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Cost price') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Sell price') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Margin') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Margin %') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100" x-show="tab === 'best'">
                                @foreach($margins['best'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['item_name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['group_name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['cost_label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['sell_label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['margin_label'] }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-slate-700">
                                            {{ $row['margin_pct_label'] }}
                                            @if($row['margin_pct'] < 0)
                                                <x-ui.badge variant="danger" class="ml-1">{{ __('Below cost') }}</x-ui.badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tbody class="divide-y divide-slate-100" x-show="tab === 'worst'" x-cloak>
                                @foreach($margins['worst'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['item_name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['group_name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['cost_label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['sell_label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['margin_label'] }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-slate-700">
                                            {{ $row['margin_pct_label'] }}
                                            @if($row['margin_pct'] < 0)
                                                <x-ui.badge variant="danger" class="ml-1">{{ __('Below cost') }}</x-ui.badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                </div>
            </div>
        </x-ui.card>
        </section>

        {{-- Section: Stock distribution --}}
        <section class="space-y-4" aria-labelledby="sec-distribution">
            <div>
                <h2 id="sec-distribution" class="text-lg font-semibold text-slate-900">{{ __('Stock distribution') }}</h2>
                <p class="text-sm text-slate-500">{{ __('Where the on-hand quantity sits, by shop and by product group.') }}</p>
            </div>
        {{-- Chart 1: Stock by shop — bar / donut / table selector --}}
        <x-ui.card>
            <div x-data="{
                view: 'bar',
                tip: { show: false, x: 0, y: 0, name: '', value: '', el: null },
                showTip(e) {
                    const el = e.currentTarget;
                    const surface = el.closest('[data-chart-surface]');
                    const rect = surface.getBoundingClientRect();
                    const elRect = el.getBoundingClientRect();
                    const x = (e.clientX ?? (elRect.left + elRect.width / 2)) - rect.left;
                    const y = (e.clientY ?? elRect.top) - rect.top;
                    this.tip = { show: true, x, y, name: el.dataset.name, value: el.dataset.value, el };
                },
                hideTip() { this.tip.show = false; this.tip.el = null; },
            }">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ __('Stock by shop') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('Total on-hand quantity per shop.') }}</p>
                    </div>
                    <div class="inline-flex shrink-0 items-center gap-0.5 rounded-lg border border-slate-300 bg-white p-0.5">
                        <button type="button" @click="view = 'bar'" :class="view === 'bar' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Bar') }}</button>
                        <button type="button" @click="view = 'donut'" :class="view === 'donut' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Donut') }}</button>
                        <button type="button" @click="view = 'table'" :class="view === 'table' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Table') }}</button>
                    </div>
                </div>

                @if(empty($shopRows))
                    <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
                @else
                    {{-- Bar view --}}
                    <div x-show="view === 'bar'">
                        <div data-chart-surface class="relative overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                            <div class="space-y-3">
                                @foreach($shopRows as $row)
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                        <div class="text-sm font-medium text-slate-900 sm:w-32 sm:shrink-0 sm:truncate md:w-40" title="{{ $row['name'] }}">
                                            {{ $row['name'] }}
                                        </div>
                                        <div class="relative h-9 flex-1">
                                            <div class="pointer-events-none absolute inset-0 flex items-stretch justify-between">
                                                @foreach ([0, 25, 50, 75, 100] as $tick)
                                                    <span class="w-px bg-[#e1e0d9]"></span>
                                                @endforeach
                                            </div>
                                            <div class="relative flex h-full items-center">
                                                <div
                                                    tabindex="0"
                                                    data-name="{{ $row['name'] }}"
                                                    data-value="{{ $row['label'] }}"
                                                    @pointermove="showTip($event)"
                                                    @pointerleave="hideTip()"
                                                    @focus="showTip($event)"
                                                    @blur="hideTip()"
                                                    :class="tip.el === $el ? 'brightness-90' : ''"
                                                    class="flex h-6 items-center rounded-r-[4px] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                    style="width: {{ max($row['percent'], 1) }}%; background-color: {{ $barColor }};"
                                                >
                                                    @if($row['percent'] >= 25)
                                                        <span class="ml-auto mr-2 truncate text-xs font-semibold text-white">{{ $row['label'] }}</span>
                                                    @endif
                                                </div>
                                                @if($row['percent'] < 25)
                                                    <span class="ml-2 shrink-0 text-xs font-medium text-slate-500">{{ $row['label'] }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div x-show="tip.show" x-cloak
                                class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                                :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                            >
                                <p x-text="tip.name" class="font-medium"></p>
                                <p x-text="tip.value"></p>
                            </div>
                        </div>
                    </div>

                    {{-- Donut view --}}
                    <div x-show="view === 'donut'" x-cloak>
                        @if(!empty($donut['segments']))
                            <div data-chart-surface class="relative flex flex-col items-center gap-6 rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4 sm:flex-row sm:justify-center">
                                <svg viewBox="0 0 160 160" class="h-40 w-40 shrink-0">
                                    <g transform="rotate(-90 80 80)">
                                        @foreach($donut['segments'] as $segment)
                                            <circle
                                                tabindex="0"
                                                data-name="{{ $segment['name'] }}"
                                                data-value="{{ $segment['label'] }}"
                                                @pointermove="showTip($event)"
                                                @pointerleave="hideTip()"
                                                @focus="showTip($event)"
                                                @blur="hideTip()"
                                                :class="tip.el === $el ? 'opacity-80' : ''"
                                                class="cursor-pointer transition focus:outline-none"
                                                cx="80" cy="80" r="{{ $donut['radius'] }}"
                                                fill="none"
                                                stroke="{{ $segment['color'] }}"
                                                stroke-width="24"
                                                stroke-dasharray="{{ $segment['dasharray'] }}"
                                                stroke-dashoffset="{{ $segment['dashoffset'] }}"
                                            ></circle>
                                        @endforeach
                                    </g>
                                </svg>

                                <ul class="w-full space-y-1.5 sm:w-auto">
                                    @foreach($donut['segments'] as $segment)
                                        <li class="flex items-center gap-2 text-sm">
                                            <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $segment['color'] }}"></span>
                                            <span class="min-w-0 flex-1 truncate text-slate-900">{{ $segment['name'] }}</span>
                                            <span class="shrink-0 font-medium text-slate-500">{{ $segment['label'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                <div x-show="tip.show" x-cloak
                                    class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                                    :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                                >
                                    <p x-text="tip.name" class="font-medium"></p>
                                    <p x-text="tip.value"></p>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Table view --}}
                    <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($shopRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- Chart 2: Stock by group — bar / donut / table selector --}}
        <x-ui.card>
            <div x-data="{
                view: 'bar',
                tip: { show: false, x: 0, y: 0, name: '', value: '', el: null },
                showTip(e) {
                    const el = e.currentTarget;
                    const surface = el.closest('[data-chart-surface]');
                    const rect = surface.getBoundingClientRect();
                    const elRect = el.getBoundingClientRect();
                    const x = (e.clientX ?? (elRect.left + elRect.width / 2)) - rect.left;
                    const y = (e.clientY ?? elRect.top) - rect.top;
                    this.tip = { show: true, x, y, name: el.dataset.name, value: el.dataset.value, el };
                },
                hideTip() { this.tip.show = false; this.tip.el = null; },
            }">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ __('Stock by group') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('Total on-hand quantity per product group.') }}</p>
                    </div>
                    <div class="inline-flex shrink-0 items-center gap-0.5 rounded-lg border border-slate-300 bg-white p-0.5">
                        <button type="button" @click="view = 'bar'" :class="view === 'bar' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Bar') }}</button>
                        <button type="button" @click="view = 'donut'" :class="view === 'donut' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Donut') }}</button>
                        <button type="button" @click="view = 'table'" :class="view === 'table' ? 'bg-brand-700 text-white' : 'text-slate-700 hover:bg-sand-50'" class="rounded-md px-2.5 py-1 text-xs font-medium transition">{{ __('Table') }}</button>
                    </div>
                </div>

                @if(empty($groupRows))
                    <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
                @else
                    <div x-show="view === 'bar'" data-chart-surface class="relative overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                        <div class="space-y-3">
                            @foreach($groupRows as $row)
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                    <div class="text-sm font-medium text-slate-900 sm:w-32 sm:shrink-0 sm:truncate md:w-40" title="{{ $row['name'] }}">
                                        {{ $row['name'] }}
                                    </div>
                                    <div class="relative h-9 flex-1">
                                        <div class="pointer-events-none absolute inset-0 flex items-stretch justify-between">
                                            @foreach ([0, 25, 50, 75, 100] as $tick)
                                                <span class="w-px bg-[#e1e0d9]"></span>
                                            @endforeach
                                        </div>
                                        <div class="relative flex h-full items-center">
                                            <div
                                                tabindex="0"
                                                data-name="{{ $row['name'] }}"
                                                data-value="{{ $row['label'] }}"
                                                @pointermove="showTip($event)"
                                                @pointerleave="hideTip()"
                                                @focus="showTip($event)"
                                                @blur="hideTip()"
                                                :class="tip.el === $el ? 'brightness-90' : ''"
                                                class="flex h-6 items-center rounded-r-[4px] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                style="width: {{ max($row['percent'], 1) }}%; background-color: {{ $barColor }};"
                                            >
                                                @if($row['percent'] >= 25)
                                                    <span class="ml-auto mr-2 truncate text-xs font-semibold text-white">{{ $row['label'] }}</span>
                                                @endif
                                            </div>
                                            @if($row['percent'] < 25)
                                                <span class="ml-2 shrink-0 text-xs font-medium text-slate-500">{{ $row['label'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div x-show="tip.show" x-cloak
                            class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                            :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                        >
                            <p x-text="tip.name" class="font-medium"></p>
                            <p x-text="tip.value"></p>
                        </div>
                    </div>

                    {{-- Donut view --}}
                    <div x-show="view === 'donut'" x-cloak>
                        @if(!empty($donutGroup['segments']))
                            <div data-chart-surface class="relative flex flex-col items-center gap-6 rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4 sm:flex-row sm:justify-center">
                                <svg viewBox="0 0 160 160" class="h-40 w-40 shrink-0">
                                    <g transform="rotate(-90 80 80)">
                                        @foreach($donutGroup['segments'] as $segment)
                                            <circle
                                                tabindex="0"
                                                data-name="{{ $segment['name'] }}"
                                                data-value="{{ $segment['label'] }}"
                                                @pointermove="showTip($event)"
                                                @pointerleave="hideTip()"
                                                @focus="showTip($event)"
                                                @blur="hideTip()"
                                                :class="tip.el === $el ? 'opacity-80' : ''"
                                                class="cursor-pointer transition focus:outline-none"
                                                cx="80" cy="80" r="{{ $donutGroup['radius'] }}"
                                                fill="none"
                                                stroke="{{ $segment['color'] }}"
                                                stroke-width="24"
                                                stroke-dasharray="{{ $segment['dasharray'] }}"
                                                stroke-dashoffset="{{ $segment['dashoffset'] }}"
                                            ></circle>
                                        @endforeach
                                    </g>
                                </svg>

                                <ul class="w-full space-y-1.5 sm:w-auto">
                                    @foreach($donutGroup['segments'] as $segment)
                                        <li class="flex items-center gap-2 text-sm">
                                            <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $segment['color'] }}"></span>
                                            <span class="min-w-0 flex-1 truncate text-slate-900">{{ $segment['name'] }}</span>
                                            <span class="shrink-0 font-medium text-slate-500">{{ $segment['label'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                <div x-show="tip.show" x-cloak
                                    class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                                    :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                                >
                                    <p x-text="tip.name" class="font-medium"></p>
                                    <p x-text="tip.value"></p>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($groupRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- Chart 3: Stock by group + shop — stacked bar, defaults to table (2D breakdown reads better tabular) --}}
        <x-ui.card>
            <div x-data="{
                view: 'table',
                tip: { show: false, x: 0, y: 0, name: '', value: '', el: null },
                showTip(e) {
                    const el = e.currentTarget;
                    const surface = el.closest('[data-chart-surface]');
                    const rect = surface.getBoundingClientRect();
                    const elRect = el.getBoundingClientRect();
                    const x = (e.clientX ?? (elRect.left + elRect.width / 2)) - rect.left;
                    const y = (e.clientY ?? elRect.top) - rect.top;
                    this.tip = { show: true, x, y, name: el.dataset.name, value: el.dataset.value, el };
                },
                hideTip() { this.tip.show = false; this.tip.el = null; },
            }">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ __('Stock by group and shop') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('Total on-hand quantity broken down by group and shop.') }}</p>
                    </div>
                    <button
                        type="button"
                        @click="view = view === 'chart' ? 'table' : 'chart'"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-sand-50"
                    >
                        <x-icon name="clipboard" class="h-4 w-4" />
                        <span x-text="view === 'chart' ? @js(__('Show table')) : @js(__('Show chart'))"></span>
                    </button>
                </div>

                @if(empty($groupShop['rows']))
                    <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
                @else
                    <div x-show="view === 'chart'">
                        <ul class="mb-4 flex flex-wrap gap-x-4 gap-y-1.5">
                            @foreach($groupShop['legend'] as $legendItem)
                                <li class="flex items-center gap-1.5 text-xs">
                                    <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $legendItem['color'] }}"></span>
                                    <span class="text-slate-500">{{ $legendItem['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div data-chart-surface class="relative overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                            <div class="space-y-3">
                                @foreach($groupShop['rows'] as $row)
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                        <div class="text-sm font-medium text-slate-900 sm:w-32 sm:shrink-0 sm:truncate md:w-40" title="{{ $row['name'] }}">
                                            {{ $row['name'] }}
                                        </div>
                                        <div class="relative h-9 flex-1">
                                            <div class="pointer-events-none absolute inset-0 flex items-stretch justify-between">
                                                @foreach ([0, 25, 50, 75, 100] as $tick)
                                                    <span class="w-px bg-[#e1e0d9]"></span>
                                                @endforeach
                                            </div>
                                            <div class="relative flex h-full items-center">
                                                <div class="flex h-6 gap-[2px] overflow-hidden rounded-r-[4px]" style="width: {{ max($row['bar_percent'], 0) }}%; background-color: #fcfcfb;">
                                                    @foreach($row['segments'] as $segment)
                                                        @php $trackPercent = $row['bar_percent'] * $segment['percent_of_bar'] / 100; @endphp
                                                        <div
                                                            tabindex="0"
                                                            data-name="{{ $segment['name'] }}"
                                                            data-value="{{ $segment['label'] }}"
                                                            @pointermove="showTip($event)"
                                                            @pointerleave="hideTip()"
                                                            @focus="showTip($event)"
                                                            @blur="hideTip()"
                                                            :class="tip.el === $el ? 'brightness-90' : ''"
                                                            class="flex h-full items-center justify-end overflow-hidden transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                            style="flex: 0 0 {{ $segment['percent_of_bar'] }}%; background-color: {{ $segment['color'] }};"
                                                        >
                                                            @if($trackPercent >= 12)
                                                                <span class="truncate px-1 text-[10px] font-semibold text-white">{{ $segment['label'] }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                                <span class="ml-2 shrink-0 text-xs font-medium text-slate-500">{{ $row['total_label'] }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div x-show="tip.show" x-cloak
                                class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                                :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                            >
                                <p x-text="tip.name" class="font-medium"></p>
                                <p x-text="tip.value"></p>
                            </div>
                        </div>
                    </div>

                    <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                                    @foreach($groupShop['legend'] as $legendItem)
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $legendItem['name'] }}</th>
                                    @endforeach
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($groupShop['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['name'] }}</td>
                                        @foreach($row['cells'] as $cell)
                                            <td class="px-4 py-3 text-sm text-slate-500">{{ $cell['label'] }}</td>
                                        @endforeach
                                        <td class="px-4 py-3 text-sm font-semibold text-slate-700">{{ $row['total_label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>
        </section>
    </div>
</div>
