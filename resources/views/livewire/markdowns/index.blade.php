@php
    $money = fn ($n) => $n === null ? '—' : number_format((float) $n, 0, '.', ' ');
    $num = fn ($n, $d = 0) => $n === null ? '—' : number_format((float) $n, $d, '.', ' ');
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $cc = config('markdown.candidates');
    $lad = config('markdown.ladder');
    $ef = config('markdown.effectiveness');
    $reasonLabel = [
        'no_sale' => __('No sale in the period'),
        'cover' => __('Cover above :n days', ['n' => $cc['cover_days']]),
        'over_max' => __('Over :n x the max rule', ['n' => $cc['over_max_multiple']]),
    ];
    $flagLabel = [
        'low_confidence' => __('Low confidence'),
        'no_speed' => __('No trusted speed'),
        'no_price' => __('No price'),
        'seasonal' => __('Sold in this period last year: may be seasonal'),
        'fresh' => __('Expiry: check by hand'),
        'cost_unknown' => __('Cost unknown'),
        'cost_limited' => __('Limited by cost'),
    ];
    $basisLabel = ['cover' => __('by cover'), 'age' => __('by last sale age'), 'over_max' => __('by the max rule')];
    $cover = fn ($r) => $r['cover'] === null ? '—' : ($r['cover_over_cap'] ? '>'.config('markdown.candidates.cover_cap_days').' '.__('d') : $num($r['cover'], 0).' '.__('d'));
    $isCand = $tab === 'candidates';
@endphp
<div>
    <x-ui.page-header :title="__('Markdown and promo')" :subtitle="__('Slow stock that may be worth a markdown, and what past discounts did. Signals to review, not instructions.')" />

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

        {{-- Always visible: change the period or the shops and generate again as often as needed. --}}
        <x-ui.button type="submit" class="h-11" wire:loading.attr="disabled" wire:target="generate" data-generate>
            <span wire:loading.remove wire:target="generate">{{ __('Generate') }}</span>
            <span wire:loading wire:target="generate" style="display: none;">{{ __('Generating report') }}…</span>
        </x-ui.button>
    </form>
    <p class="mb-4 text-xs text-slate-500">{{ __('Default period of sales evidence: the last :n whole days ending yesterday (:min to :max days).', ['n' => config('markdown.default_days'), 'min' => config('markdown.min_days'), 'max' => config('markdown.max_days')]) }}</p>

    <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900" data-shelf-life-warning>{{ __('No shelf-life data: candidates are slow stock, not expiry risk.') }}</p>

    <details class="mb-6 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
        <summary class="cursor-pointer font-semibold text-slate-900">{{ __('How this is calculated') }}</summary>
        <ul class="mt-3 list-disc space-y-1.5 pl-5">
            <li>{{ __('Sale = active and sell receipt, storno lines left out, refunds not netted (the same rule as Stock without sales). On hand = the current stock reported by the shop\'s latest 1C sync; pairs not reported are only counted.') }}</li>
            <li>{{ __('Left out: shops with less than :s days of data, items first seen less than :i days ago, pairs with an open order for the same shop and item, shops that are disabled and the main warehouse.', ['s' => $cc['min_shop_history_days'], 'i' => $cc['min_item_age_days']]) }}</li>
            <li>{{ __('Candidate when: no sale in the period, or cover (on hand / sales speed from the Ordering forecast) above :c days, or on hand above :m x the shop\'s max rule. Without a trusted speed (no rule, no history or low confidence with no sales) the cover is not computed; it is never read as infinite. Cover above :cap days is shown as ">:cap d".', ['c' => $cc['cover_days'], 'm' => $cc['over_max_multiple'], 'cap' => $cc['cover_cap_days']]) }}</li>
            <li>{{ __('Small-stock guard: at least :u units and, when a price is known, at least :v value at the sell price (the lowest selling price; the cost price is never used for it). Items without a price are kept and shown "No price".', ['u' => $cc['min_units'], 'v' => $money($cc['min_value'])]) }}</li>
            <li>{{ __('Suggested start, test and adjust: cover :c1+ days :d1 %, cover :c2+ days :d2 %, no sale in the period :n %, no sale for :o+ days :od % (never sold: the item must be that old), over the max rule only :om %. The deeper of the cover-based and the last-sale-based rung is suggested.', ['c1' => $lad['cover'][0]['from'], 'd1' => $lad['cover'][0]['depth'], 'c2' => $lad['cover'][1]['from'], 'd2' => $lad['cover'][1]['depth'], 'n' => $lad['no_sale_depth'], 'o' => $lad['old_sale_days'], 'od' => $lad['old_sale_depth'], 'om' => $lad['over_max_depth']]) }}</li>
            <li>{{ __('Transfer first: another selected shop sells the item with less than :t days of cover and the Ordering forecast needs it; shown before a markdown. An operator sees only his own shops as targets.', ['t' => $cc['transfer_cover_days']]) }}</li>
            <li>{{ __('Pieces are whole pieces; case and pallet effects are not modelled. Items in short-life groups are marked "Expiry: check by hand" and get no depth.') }}</li>
            <li>{{ __('Discount effectiveness: a discounted line has a discount above :l % of its sum; a discounted day has at least :s % of the units sold discounted with depths within :p points; a full-price day has at most :f % discounted. Only days with stock and a working receipt feed count; units are net of refunds of the same day.', ['l' => $ef['line_min_depth'] * 100, 's' => $ef['day_min_share'] * 100, 'p' => $ef['depth_spread'] * 100, 'f' => $ef['full_price_max_share'] * 100]) }}</li>
            <li>{{ __('Lift = units per in-stock day on discounted days / units per in-stock day on full-price days, with the full-price days weighted to the same weekday mix. Needs at least :d discounted and :f full-price days, otherwise "n/a". Give-away = the discount on the discounted units. Cannibalised: lift below :cl and give-away above :cg. Rarely sold at full price: more than :r % of the units sold discounted. Episodes are inferred from receipts, not a recorded markdown.', ['d' => $ef['min_discounted_days'], 'f' => $ef['min_full_price_days'], 'cl' => $ef['cannibalised_lift'], 'cg' => $money($ef['cannibalised_min_giveaway']), 'r' => $ef['rarely_full_share'] * 100]) }}</li>
        </ul>
    </details>

    @if($noShops)
        <x-ui.no-shops />
    @elseif(! $generated)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick the period and shops, then press Generate.')" />
    @else
        <div class="mb-4 inline-flex overflow-hidden rounded-lg border border-slate-300 bg-white text-sm shadow-sm" role="group" aria-label="{{ __('View') }}">
            <button type="button" wire:click="setTab('candidates')" aria-pressed="{{ $isCand ? 'true' : 'false' }}" class="px-3 py-2 font-medium {{ $isCand ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-50' }}">{{ __('Candidates') }}</button>
            <button type="button" wire:click="setTab('discounts')" aria-pressed="{{ $isCand ? 'false' : 'true' }}" class="border-l border-slate-300 px-3 py-2 font-medium {{ $isCand ? 'text-slate-600 hover:bg-slate-50' : 'bg-brand-700 text-white' }}">{{ __('Discount effectiveness') }}</button>
        </div>

        @if(($report['window'] ?? null) === null)
            <x-ui.empty-state :title="__('No shops')" :description="__('There are no shops to analyse for this selection.')" />
        @elseif($isCand)
            @php
                $k = $report['kpi'];
                $w = $report['window'];
            @endphp
            <p class="mb-3 text-xs text-slate-500">
                {{ __('Period: :from to :to (:days days). Figures as of :as_of.', ['from' => $w['from'], 'to' => $w['to'], 'days' => $w['days'], 'as_of' => $report['as_of']]) }}
                @if($k['stock_as_of']) {{ __('Stock as of :date.', ['date' => $k['stock_as_of']]) }} @endif
            </p>
            @if($k['stock_stale'])
                <p class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">{{ __('The stock snapshot is more than :n days old: the list may be out of date.', ['n' => \App\Services\UnsoldStock::STALE_STOCK_DAYS]) }}</p>
            @endif
            @if(! $report['seasonal_known'])
                <p class="mb-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-800" data-seasonal-warning>{{ __('There is no receipt history for the same period last year: seasonal items can not be told apart. Check the season by hand before a markdown.') }}</p>
            @endif

            <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" data-kpi>
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Candidates') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900" data-kpi-candidates>{{ $money($k['candidates']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __(':n small stocks left out', ['n' => $k['small_stock']]) }}</p>
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Units') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['units']) }}</p>
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Value at sell price') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['value']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __(':n items have no price and are not counted.', ['n' => $k['no_price']]) }}</p>
                </x-ui.card>
                @if($withProfit)
                    <x-ui.card>
                        <p class="text-xs text-slate-500">{{ __('Value at cost') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['value_cost']) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __(':n items have no cost price and are not counted.', ['n' => $k['without_cost']]) }}</p>
                    </x-ui.card>
                @else
                    <x-ui.card>
                        <p class="text-xs text-slate-500">{{ __('Delist candidates') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['delist']) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('rule min and max are both 0') }}</p>
                    </x-ui.card>
                @endif
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Not reported by 1C') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900" data-kpi-not-reported>{{ $money($k['not_reported']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('counted, not judged') }}</p>
                </x-ui.card>
                @if($withProfit)
                    <x-ui.card>
                        <p class="text-xs text-slate-500">{{ __('Delist candidates') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['delist']) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('rule min and max are both 0') }}</p>
                    </x-ui.card>
                @endif
            </div>
            <p class="mb-4 text-xs text-slate-500">{{ __('Left out: :y pairs in shops with too little history, :n pairs of new items, :o pairs with an open order.', ['y' => $k['excluded']['young_shop'], 'n' => $k['excluded']['new_item'], 'o' => $k['excluded']['open_order']]) }}</p>

            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Markdown candidates') }}</h2>
            <p class="mb-2 text-xs text-slate-500">{{ __('Depth is a suggested start, test and adjust; the deeper of the cover-based and the last-sale-based rung wins. Consider a transfer to a shop that sells the item before a markdown.') }}</p>
            <div class="mb-3 flex flex-wrap items-end gap-3" data-filters>
                <div class="w-full sm:w-56"><x-ui.input name="search" :label="__('Search')" wire:model.live.debounce.300ms="search" placeholder="{{ __('Item name') }}" /></div>
                <div class="w-full sm:w-44">
                    <x-ui.select name="group" :label="__('Group')" wire:model.live="group">
                        <option value="">{{ __('All groups') }}</option>
                        @foreach($groupOptions as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach
                    </x-ui.select>
                </div>
                <div class="w-full sm:w-44">
                    <x-ui.select name="shopFilter" :label="__('Shop')" wire:model.live="shopFilter">
                        <option value="">{{ __('All shops') }}</option>
                        @foreach($shops as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </x-ui.select>
                </div>
                <div class="w-full sm:w-48">
                    <x-ui.select name="reason" :label="__('Reason')" wire:model.live="reason">
                        <option value="">{{ __('All reasons') }}</option>
                        @foreach($reasonLabel as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                    </x-ui.select>
                </div>
                <div class="w-full sm:w-36"><x-ui.input name="minValue" type="number" min="0" :label="__('Value, from')" wire:model.live.debounce.400ms="minValue" /></div>
                <div class="w-full sm:w-40">
                    <x-ui.select name="sort" :label="__('Sort by')" wire:model.live="sort">
                        <option value="value">{{ __('Value at sell price') }}</option>
                        <option value="cover">{{ __('Cover') }}</option>
                        <option value="days">{{ __('Days since last sale') }}</option>
                        <option value="name">{{ __('Name') }}</option>
                    </x-ui.select>
                </div>
            </div>

            @if($rows->total() === 0)
                <x-ui.empty-state :title="__('Nothing to show')" :description="__('No pair matches the filters.')" />
            @else
                @php
                    $lastSale = fn ($r) => ! in_array('no_sale', $r['reasons'], true) ? __('sold in the period') : ($r['last_sale'] === null ? __('no sale in the data we have') : __(':n days ago', ['n' => $r['days_since']]));
                @endphp
                <div class="space-y-3 md:hidden">
                    @foreach($rows as $r)
                        <div wire:key="card-{{ $r['item_id'] }}-{{ $r['shop_id'] }}" class="rounded-lg border border-slate-200 bg-white p-4">
                            <p class="font-medium"><a href="{{ route('items.show', $r['item_id']) }}" class="text-brand-700 hover:underline">{{ $r['name'] }}</a></p>
                            <p class="text-xs text-slate-500">{{ $r['shop'] }}@if($r['group']) · {{ $r['group'] }}@endif</p>
                            <p class="mt-1 flex flex-wrap gap-1">@foreach($r['reasons'] as $code)<x-ui.badge variant="warning">{{ $reasonLabel[$code] }}</x-ui.badge>@endforeach</p>
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-600">
                                <div><dt>{{ __('On hand') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $num($r['on_hand']) }}</dd></div>
                                <div><dt>{{ __('Value at sell price') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $r['value'] === null ? __('No price') : $money($r['value']) }}</dd></div>
                                <div><dt>{{ __('Last sale') }}</dt><dd class="text-sm text-slate-900">{{ $lastSale($r) }}</dd></div>
                                <div><dt>{{ __('Speed per day') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $num($r['speed'], 2) }}</dd></div>
                                <div><dt>{{ __('Cover') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $cover($r) }}</dd></div>
                                <div><dt>{{ __('Suggested depth') }}</dt><dd class="tabular-nums text-sm font-semibold text-slate-900">{{ $r['depth'] === null ? '—' : $r['depth'].' %' }}</dd></div>
                            </dl>
                            @if($r['transfer'])<p class="mt-2 rounded bg-brand-50 px-2 py-1 text-xs text-brand-700">{{ __('Transfer first: :q pcs to :shop, it sells there.', ['q' => $num($r['transfer']['qty']), 'shop' => $r['transfer']['shop']]) }}</p>@endif
                            <p class="mt-1 flex flex-wrap gap-1">@foreach($r['flags'] as $f)<x-ui.badge>{{ $flagLabel[$f] ?? $f }}</x-ui.badge>@endforeach</p>
                        </div>
                    @endforeach
                </div>

                <x-ui.card padding="p-0" class="hidden md:block">
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                        <thead><tr>
                            <th class="{{ $th }} text-left">{{ __('Item') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Group') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Shop') }}</th>
                            <th class="{{ $th }} text-right">{{ __('On hand') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Value at sell price') }}</th>
                            @if($withProfit)<th class="{{ $th }} text-right">{{ __('Value at cost') }}</th>@endif
                            <th class="{{ $th }} text-right">{{ __('Last sale') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Speed per day') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Cover') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Reasons') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Suggested depth') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Transfer first') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Flags') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                        @foreach($rows as $r)
                            <tr wire:key="row-{{ $r['item_id'] }}-{{ $r['shop_id'] }}">
                                <td class="px-3 py-2"><a href="{{ route('items.show', $r['item_id']) }}" class="text-brand-700 hover:underline">{{ $r['name'] }}</a></td>
                                <td class="px-3 py-2 text-slate-600">{{ $r['group'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $r['shop'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ $num($r['on_hand']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $r['value'] === null ? __('No price') : $money($r['value']) }}</td>
                                @if($withProfit)<td class="px-3 py-2 text-right tabular-nums">{{ ($r['cost_value'] ?? null) === null ? __('Cost unknown') : $money($r['cost_value']) }}</td>@endif
                                <td class="px-3 py-2 text-right text-xs">{{ $lastSale($r) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['speed'], 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $cover($r) }}</td>
                                <td class="px-3 py-2"><span class="flex flex-wrap gap-1">@foreach($r['reasons'] as $code)<x-ui.badge variant="warning">{{ $reasonLabel[$code] }}</x-ui.badge>@endforeach</span></td>
                                <td class="px-3 py-2 text-right tabular-nums">@if($r['depth'] === null)—@else<span class="font-semibold">{{ $r['depth'] }} %</span> <span class="text-xs text-slate-500">{{ $basisLabel[$r['depth_basis']] ?? '' }}</span>@if(isset($r['cost_limited_from']))<span class="block text-xs text-amber-700">{{ __('Limited by cost from :n %', ['n' => $r['cost_limited_from']]) }}</span>@endif @endif</td>
                                <td class="px-3 py-2 text-xs">@if($r['transfer']){{ __(':q pcs to :shop', ['q' => $num($r['transfer']['qty']), 'shop' => $r['transfer']['shop']]) }}@else<span class="text-slate-400">—</span>@endif</td>
                                <td class="px-3 py-2"><span class="flex flex-wrap gap-1">@foreach($r['flags'] as $f)<x-ui.badge>{{ $flagLabel[$f] ?? $f }}</x-ui.badge>@endforeach</span></td>
                            </tr>
                        @endforeach
                        </tbody></table></div>
                </x-ui.card>
                <div class="mt-4">{{ $rows->links() }}</div>
                @if($k['truncated'])
                    <p class="mt-2 text-xs text-slate-500">{{ __('The list holds the biggest :n rows; the totals above count everything.', ['n' => config('markdown.candidates.max_rows')]) }}</p>
                @endif
            @endif

            @if($delist !== [])
                <h2 class="mb-2 mt-8 text-base font-semibold text-slate-900">{{ __('Delist candidates') }}</h2>
                <p class="mb-2 text-xs text-slate-500">{{ __('The rule says min 0 and max 0 (the shop is not meant to hold the item) but stock is left. Review whether to sell it out or take it back.') }}</p>
                <x-ui.card padding="p-0">
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                        <thead><tr>
                            <th class="{{ $th }} text-left">{{ __('Item') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Shop') }}</th>
                            <th class="{{ $th }} text-right">{{ __('On hand') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Value at sell price') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Sales') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                        @foreach($delist as $r)
                            <tr wire:key="del-{{ $r['item_id'] }}-{{ $r['shop_id'] }}">
                                <td class="px-3 py-2"><a href="{{ route('items.show', $r['item_id']) }}" class="text-brand-700 hover:underline">{{ $r['name'] }}</a></td>
                                <td class="px-3 py-2">{{ $r['shop'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['on_hand']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $r['value'] === null ? __('No price') : $money($r['value']) }}</td>
                                <td class="px-3 py-2 text-xs">{{ $r['no_sale'] ? __('No sale in the period') : __('Sold in the period') }}</td>
                            </tr>
                        @endforeach
                        </tbody></table></div>
                </x-ui.card>
            @endif
        @else
            @php
                $k = $report['kpi'];
                $w = $report['window'];
            @endphp
            <p class="mb-3 text-xs text-slate-500">{{ __('Period: :from to :to (:days days). Figures as of :as_of.', ['from' => $w['from'], 'to' => $w['to'], 'days' => $w['days'], 'as_of' => $report['as_of']]) }}</p>

            <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800" data-confounders>
                <p class="font-semibold text-slate-900">{{ __('The page can see the discounts on receipt lines, not their cause.') }}</p>
                <p class="mt-1">{{ __('A change in sales on discounted days can also come from pay days, a chain-wide promo, a price change, a stock-out just before or after, other items in the basket (halo) or other items losing sales (cannibalisation), loyalty-card discounts, or items that react strongly to price. Treat lift as a signal to review, not as proof.') }}</p>
            </div>

            <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" data-kpi>
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Pairs with discounted days') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['pairs']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __(':a analysed, :j with a lift, :n n/a', ['a' => $k['analysed'], 'j' => $k['judged'], 'n' => $k['na']]) }}</p>
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Give-away') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['give_away']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('discount on the discounted units') }}</p>
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Cannibalised') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums {{ $k['cannibalised'] > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ $money($k['cannibalised']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('lift below :l with a large give-away', ['l' => $ef['cannibalised_lift']]) }}</p>
                </x-ui.card>
                <x-ui.card>
                    <p class="text-xs text-slate-500">{{ __('Rarely sold at full price') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['rarely_full']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('more than :r % of the units sold discounted', ['r' => $ef['rarely_full_share'] * 100]) }}</p>
                </x-ui.card>
            </div>
            @if($k['silent_shop_days'] > 0)
                <p class="mb-4 text-xs text-slate-500">{{ __(':n shop days with a silent receipt feed were left out (missing data, not zero sales).', ['n' => $k['silent_shop_days']]) }}</p>
            @endif

            @if($rows->total() === 0)
                <x-ui.empty-state :title="__('No discounted days found')" :description="__('No item had days with most units sold at a discount in this period.')" />
            @else
                @php
                    $liftText = fn ($r) => $r['status'] === 'ok' ? 'x'.$num($r['lift'], 2) : ($r['reason'] === 'no_base' ? __('n/a: no full-price sales') : __('n/a: not enough days'));
                @endphp
                <div class="space-y-3 md:hidden">
                    @foreach($rows as $r)
                        <div wire:key="dcard-{{ $r['item_id'] }}-{{ $r['shop_id'] }}" class="rounded-lg border border-slate-200 bg-white p-4">
                            <p class="font-medium"><a href="{{ route('items.show', $r['item_id']) }}" class="text-brand-700 hover:underline">{{ $r['name'] }}</a></p>
                            <p class="text-xs text-slate-500">{{ $r['shop'] }}@if($r['group']) · {{ $r['group'] }}@endif</p>
                            <p class="mt-1 flex flex-wrap gap-1">
                                @if($r['cannibalised'])<x-ui.badge variant="danger">{{ __('Cannibalised') }}</x-ui.badge>@endif
                                @if($r['rarely_full'])<x-ui.badge variant="warning">{{ __('Rarely sold at full price') }}</x-ui.badge>@endif
                            </p>
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-600">
                                <div><dt>{{ __('Lift') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $liftText($r) }}</dd></div>
                                <div><dt>{{ __('Average depth') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $num($r['depth'], 1) }}{{ $r['depth'] === null ? '' : ' %' }}</dd></div>
                                <div><dt>{{ __('Discounted / full-price days') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $r['disc_days'] }} / {{ $r['full_days'] }}</dd></div>
                                <div><dt>{{ __('Give-away') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $money($r['give_away']) }}</dd></div>
                                <div><dt>{{ __('Incremental revenue (proxy)') }}</dt><dd class="tabular-nums text-sm text-slate-900">{{ $money($r['incremental']) }}</dd></div>
                            </dl>
                        </div>
                    @endforeach
                </div>

                <x-ui.card padding="p-0" class="hidden md:block">
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                        <thead><tr>
                            <th class="{{ $th }} text-left">{{ __('Item') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Shop') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Discounted / full-price days') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Average depth') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Lift') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Give-away') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Incremental revenue (proxy)') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Labels') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Episodes (inferred, not a recorded markdown)') }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                        @foreach($rows as $r)
                            <tr wire:key="drow-{{ $r['item_id'] }}-{{ $r['shop_id'] }}">
                                <td class="px-3 py-2"><a href="{{ route('items.show', $r['item_id']) }}" class="text-brand-700 hover:underline">{{ $r['name'] }}</a></td>
                                <td class="px-3 py-2">{{ $r['shop'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $r['disc_days'] }} / {{ $r['full_days'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $num($r['depth'], 1) }}{{ $r['depth'] === null ? '' : ' %' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $liftText($r) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $money($r['give_away']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $money($r['incremental']) }}</td>
                                <td class="px-3 py-2"><span class="flex flex-wrap gap-1">
                                    @if($r['cannibalised'])<x-ui.badge variant="danger">{{ __('Cannibalised') }}</x-ui.badge>@endif
                                    @if($r['rarely_full'])<x-ui.badge variant="warning">{{ __('Rarely sold at full price') }} ({{ $num($r['disc_share'], 0) }} %)</x-ui.badge>@endif
                                </span></td>
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    @forelse($r['episodes'] as $e)
                                        <p>{{ $e['start'] }}@if($e['end'] !== $e['start']) – {{ $e['end'] }}@endif: {{ __('before :b, during :d, after :a per day', ['b' => $num($e['before'], 2), 'd' => $num($e['during'], 2), 'a' => $num($e['after'], 2)]) }}</p>
                                    @empty —
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                        </tbody></table></div>
                </x-ui.card>
                <div class="mt-4">{{ $rows->links() }}</div>
                @if($k['truncated'])
                    <p class="mt-2 text-xs text-slate-500">{{ __('Only the pairs with the largest give-away were analysed (:n).', ['n' => $ef['max_pairs']]) }}</p>
                @endif
            @endif
        @endif
    @endif
</div>
