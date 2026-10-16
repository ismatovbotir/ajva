@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $num = fn ($n, $d = 0) => number_format((float) $n, $d, '.', ' ');
    $pct = fn ($n) => number_format((float) $n, 1, '.', ' ').' %';
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $levelClass = ['warn' => 'border-amber-300 bg-amber-50 text-amber-900', 'info' => 'border-slate-200 bg-slate-50 text-slate-800'];
    $perShop = $mode === 'shop';
@endphp
<div>
    <x-ui.page-header :title="__('Stock without sales')" :subtitle="__('Items that are in stock but did not sell at all in the selected period.')" />

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

        {{-- Always visible: change the period or the shops and generate again as often as needed. --}}
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="generate" data-generate>
            <span wire:loading.remove wire:target="generate">{{ __('Generate') }}</span>
            <span wire:loading wire:target="generate" style="display: none;">{{ __('Generating report') }}…</span>
        </x-ui.button>
    </form>
    <p class="mb-6 text-xs text-slate-500">{{ __('Recommended period: 60 to 90 days. A short period flags slow items that are not really dead, and an item that just arrived also appears until it sells. Sales = successful receipts, storno lines excluded, refunds not netted.') }}</p>

    @if($noShops)
        <x-ui.no-shops />
    @elseif(! $generated)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick the period and shops, then press Generate.')" />
    @elseif(($report['kpi']['stocked_items'] ?? 0) === 0)
        <x-ui.empty-state :title="__('No stock')" :description="__('There is no stock in the selected shops.')" />
    @else
        @php($k = $report['kpi'])
        @php($w = $report['window'])

        <p class="mb-3 text-xs text-slate-500">
            {{ __('Period: :from to :to (:days days).', ['from' => $w['from'], 'to' => $w['to'], 'days' => $w['days']]) }}
            @if($k['stock_as_of']) {{ __('Stock as of :date.', ['date' => $k['stock_as_of']]) }} @endif
        </p>

        {{-- KPI strip --}}
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" data-kpi>
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ __('Items in stock') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['stocked_items']) }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ __('Items without sales') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $k['unsold_items'] > 0 ? 'text-amber-700' : 'text-slate-900' }}" data-kpi-unsold>{{ $money($k['unsold_items']) }}@if($k['unsold_items'] > 0) <span class="sr-only">{{ __('needs attention') }}</span><span aria-hidden="true" class="text-base">&#9888;</span>@endif</p>
                <p class="mt-1 text-xs text-slate-500">{{ $pct($k['unsold_share']) }} {{ __('of the items in stock') }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-slate-500">{{ __('Units that did not move') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $num($k['unsold_units']) }}</p>
            </x-ui.card>
            <x-ui.card>
                @if($k['value'])
                    <p class="text-xs text-slate-500">{{ __('Value at cost') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['value']['unsold']) }}</p>
                    @if($k['value']['without_cost'] > 0)<p class="mt-1 text-xs text-slate-500">{{ __(':n items have no cost price and are not counted.', ['n' => $k['value']['without_cost']]) }}</p>@endif
                @else
                    <p class="text-xs text-slate-500">{{ __('Item and shop pairs without sales') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $money($k['unsold_pairs']) }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('stock in a shop that did not sell the item there') }}</p>
                @endif
            </x-ui.card>
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

        {{-- Groups --}}
        @if($report['groups'])
            <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Groups with the most unsold items') }}</h2>
            <x-ui.card padding="p-0" class="mb-6">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr><th class="{{ $th }} text-left">{{ __('Group') }}</th><th class="{{ $th }} text-right">{{ __('Unsold items') }}</th><th class="{{ $th }} text-right">{{ __('In stock') }}</th><th class="{{ $th }} text-left">{{ __('Share') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($report['groups'] as $g)
                        <tr wire:key="grp-{{ $loop->index }}"><td class="px-3 py-2">{{ $g['group'] !== '' ? $g['group'] : __('No group') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $money($g['unsold']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $money($g['stocked']) }}</td>
                            <td class="px-3 py-2"><span class="flex items-center gap-2"><span class="h-2 w-32 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-amber-500" style="width: {{ min(100, round($g['share'])) }}%"></span></span><span class="tabular-nums text-xs text-slate-600">{{ $pct($g['share']) }}</span></span></td></tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
        @endif

        {{-- List --}}
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Items without sales') }}</h2>
        <div class="mb-3 flex flex-wrap items-end gap-3" data-filters>
            <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 bg-white text-sm shadow-sm" role="group" aria-label="{{ __('View') }}">
                <button type="button" wire:click="$set('mode', 'item')" aria-pressed="{{ $perShop ? 'false' : 'true' }}" class="px-3 py-2 font-medium {{ $perShop ? 'text-slate-600 hover:bg-slate-50' : 'bg-brand-700 text-white' }}">{{ __('Per item') }}</button>
                <button type="button" wire:click="$set('mode', 'shop')" aria-pressed="{{ $perShop ? 'true' : 'false' }}" class="border-l border-slate-300 px-3 py-2 font-medium {{ $perShop ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-50' }}">{{ __('Per item and shop') }}</button>
            </div>
            <div class="w-full sm:w-56"><x-ui.input name="search" :label="__('Search')" wire:model.live.debounce.300ms="search" placeholder="{{ __('Item name') }}" /></div>
            <div class="w-full sm:w-48">
                <x-ui.select name="group" :label="__('Group')" wire:model.live="group">
                    <option value="">{{ __('All groups') }}</option>
                    @foreach($groupOptions as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-32"><x-ui.input name="minOnHand" type="number" min="0" :label="__('On hand, from')" wire:model.live.debounce.400ms="minOnHand" /></div>
            <div class="w-full sm:w-40">
                <x-ui.select name="sort" :label="__('Sort by')" wire:model.live="sort">
                    <option value="on_hand">{{ __('On hand') }}</option>
                    <option value="name">{{ __('Name') }}</option>
                    @if($report['profit'])<option value="value">{{ __('Value at cost') }}</option>@endif
                </x-ui.select>
            </div>
        </div>
        <p class="mb-2 text-xs text-slate-500">{{ $perShop ? __('Stock in a shop that did not sell the item there. It may sell in another shop: a candidate for a transfer.') : __('Not sold in any of the selected shops.') }}</p>

        @if($rows->total() === 0)
            <x-ui.empty-state :title="__('Nothing to show')" :description="__('No item matches the filters.')" />
        @else
            <x-ui.card padding="p-0">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200">
                    <thead><tr>
                        <th class="{{ $th }} text-left">{{ __('Item') }}</th>
                        <th class="{{ $th }} text-left">{{ __('Group') }}</th>
                        @if($perShop)<th class="{{ $th }} text-left">{{ __('Shop') }}</th>@else<th class="{{ $th }} text-right">{{ __('Shops with stock') }}</th>@endif
                        <th class="{{ $th }} text-right">{{ __('On hand') }}</th>
                        @if($report['profit'])<th class="{{ $th }} text-right">{{ __('Value at cost') }}</th>@endif
                        <th class="{{ $th }} text-right">{{ __('Last sale') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                    @foreach($rows as $r)
                        <tr wire:key="row-{{ $perShop ? ($r['item_id'].'-'.$r['shop_id']) : $r['id'] }}">
                            <td class="px-3 py-2">{{ $r['name'] }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $r['group'] ?? '—' }}</td>
                            @if($perShop)<td class="px-3 py-2">{{ $r['shop'] }}</td>@else<td class="px-3 py-2 text-right tabular-nums">{{ $r['shops'] }}</td>@endif
                            <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ $num($r['on_hand'], 0) }}</td>
                            @if($report['profit'])<td class="px-3 py-2 text-right tabular-nums">{{ $r['value'] === null ? __('no cost price') : $money($r['value']) }}</td>@endif
                            <td class="px-3 py-2 text-right tabular-nums">
                                @if($r['last_sale'] === null)<span class="font-medium text-red-700">{{ __('never sold') }}</span>
                                @else{{ $r['last_sale'] }} <span class="text-xs text-slate-500">({{ __(':n days before', ['n' => $r['days_since']]) }})</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody></table></div>
            </x-ui.card>
            <div class="mt-4">{{ $rows->links() }}</div>
            @if($k['truncated'])
                <p class="mt-2 text-xs text-slate-500">{{ __('The list holds the biggest :n rows; the totals above count everything.', ['n' => \App\Services\UnsoldStock::MAX_ROWS]) }}</p>
            @endif
        @endif
    @endif
</div>
