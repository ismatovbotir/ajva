@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
    $abcBadge = ['A' => 'danger', 'B' => 'warning', 'C' => 'default'];
    $abcBar = ['A' => 'bg-red-600', 'B' => 'bg-amber-400', 'C' => 'bg-slate-300'];
    $ok = $data['state'] === 'ok';
    $showKpi = $ok && ! $data['partial'] && $data['kpi'] !== null;
    $th = 'px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp
<div>
    <x-ui.page-header :title="__('Out of stock (main warehouse)')" :subtitle="__('Items the main warehouse no longer has, found from the daily 1C stock data.')">
        <x-slot:actions>
            <x-ui.button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" data-generate>{{ __('Generate') }}</x-ui.button>
            @if($ok)
                <a href="{{ $csvUrl }}" data-csv class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-sand-50">{{ __('Download CSV') }}</a>
                <a href="{{ $printUrl }}" target="_blank" rel="noopener" data-print class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-sand-50">{{ __('Print') }}</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if($data['state'] === 'pending')
        <x-ui.empty-state :title="__('No report yet')" :description="__('Press Generate to find the items the main warehouse has run out of.')" />
    @elseif($data['state'] === 'unconfigured')
        <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-no-warehouse>
            {{ __('The main warehouse is not set (Settings > Warehouse).') }}
            <a href="{{ route('settings.warehouse') }}" class="font-medium underline">{{ __('Open Settings > Warehouse') }}</a>
        </p>
    @elseif($data['state'] === 'empty')
        <x-ui.empty-state :title="__('No warehouse stock data yet')" :description="__('1C has not sent any stock for the main warehouse.')" />
    @elseif($data['state'] === 'not_synced')
        <p class="mb-4 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-800" data-not-synced>
            {{ __('Warehouse not synced since :date', ['date' => $data['ref']]) }}.
            {{ __('Other shops have newer stock (:date), so no item is listed as out of stock.', ['date' => $data['chain']]) }}
        </p>
    @else
        <p class="mb-3 text-sm text-slate-500" data-ref>
            {{ __('Warehouse stock as of :date.', ['date' => $data['ref']]) }}
            @if($data['coverage'] !== null)
                {{ __('Sync coverage: :pct% of the previous sync.', ['pct' => round($data['coverage'] * 100)]) }}
            @endif
        </p>

        @if($data['partial'])
            <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-partial>
                {{ __('The latest warehouse sync looks incomplete. The list below is only a warning, check the sync in 1C.') }}
            </p>
        @endif

        @if($showKpi)
            <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" data-kpi>
                <div class="rounded-xl border p-3 shadow-sm {{ $data['kpi']['out'] > 0 ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white' }}">
                    <p class="text-xs font-medium {{ $data['kpi']['out'] > 0 ? 'text-red-800' : 'text-slate-500' }}">{{ __('Out of stock at the warehouse') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $data['kpi']['out'] > 0 ? 'text-red-800' : 'text-slate-400' }}" data-kpi-out>{{ $data['kpi']['out'] }}</p>
                    <p class="text-xs text-slate-500">{{ __('A-class: :a', ['a' => $data['kpi']['a']]) }} &middot; {{ __(':pct% of :count items stocked in the last :days days', ['pct' => $data['kpi']['share'], 'count' => $data['candidates'], 'days' => $data['history_days']]) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                    <p class="text-xs font-medium text-slate-500">{{ __('Demand at risk') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-brand-700" data-kpi-need>{{ $fmt($data['kpi']['need']) }}</p>
                    <p class="text-xs text-slate-500">{{ __('Units the shops need, in :count items', ['count' => $data['kpi']['need_items']]) }}</p>
                </div>
                <x-ui.card padding="p-3" class="sm:col-span-2" data-histogram>
                    <p class="text-xs font-medium text-slate-500">{{ __('Days out of stock') }}</p>
                    @php $maxBucket = max(1, collect($data['histogram'])->map(fn ($b) => array_sum($b))->max()); @endphp
                    <div class="mt-2 flex h-24 items-end gap-2">
                        @foreach($data['histogram'] as $label => $b)
                            @php $sum = array_sum($b); @endphp
                            <div class="flex h-full min-w-0 flex-1 flex-col justify-end text-center" title="{{ $label }}: {{ $sum }}">
                                <span class="text-xs font-semibold tabular-nums text-slate-700">{{ $sum }}</span>
                                <div class="flex w-full flex-col-reverse overflow-hidden rounded-t" style="height: {{ round(100 * $sum / $maxBucket * 0.7, 1) }}%">
                                    @foreach(['A', 'B', 'C'] as $cls)
                                        @if($b[$cls] > 0)
                                            <span class="{{ $abcBar[$cls] }}" style="flex: {{ $b[$cls] }} 1 0" title="ABC {{ $cls }}: {{ $b[$cls] }}"></span>
                                        @endif
                                    @endforeach
                                </div>
                                <span class="mt-1 text-xs text-slate-500">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-1 flex gap-3 text-xs text-slate-500">
                        @foreach(['A', 'B', 'C'] as $cls)
                            <span class="inline-flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm {{ $abcBar[$cls] }}" aria-hidden="true"></span>ABC {{ $cls }}</span>
                        @endforeach
                    </p>
                </x-ui.card>
            </div>
        @endif

        {{-- Filters --}}
        <div class="mb-4 grid grid-cols-2 items-end gap-3 lg:grid-cols-6" data-filters>
            <div class="col-span-2 lg:col-span-2">
                <x-ui.input name="search" :label="__('Search')" wire:model.live.debounce.300ms="search" placeholder="{{ __('Name, SKU or barcode') }}" />
            </div>
            <x-ui.select name="group" :label="__('Group')" wire:model.live="group">
                <option value="">{{ __('All groups') }}</option>
                @foreach($data['groups'] as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="abc" :label="__('ABC class')" wire:model.live="abc">
                <option value="">{{ __('All') }}</option>
                @foreach(['A', 'B', 'C'] as $cls)
                    <option value="{{ $cls }}">{{ $cls }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="reason" :label="__('Reason')" wire:model.live="reason">
                <option value="">{{ __('All') }}</option>
                <option value="missing">{{ __('Missing from the sync') }}</option>
                <option value="zero">{{ __('Zero quantity') }}</option>
            </x-ui.select>
            <x-ui.input name="minDays" type="number" min="0" :label="__('Days out, from')" wire:model.live.debounce.400ms="minDays" />
            <label class="col-span-2 flex items-center gap-2 text-sm text-slate-700 lg:col-span-6">
                <input type="checkbox" wire:model.live="needed" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                {{ __('Only items the shops need') }}
            </label>
        </div>

        @if($data['excluded'] > 0)
            <p class="mb-2 text-xs text-slate-500" data-excluded>{{ __(':count items with shop rules were not in stock at the warehouse in the last :days days and are not listed.', ['count' => $data['excluded'], 'days' => $data['history_days']]) }}</p>
        @endif
        @if($data['kpi'] && $data['kpi']['negative'] > 0)
            <p class="mb-2 text-xs font-medium text-amber-700" data-negative>{{ __(':count items have a negative quantity at the warehouse. Check the data in 1C.', ['count' => $data['kpi']['negative']]) }}</p>
        @endif

        @if($rows->isEmpty())
            <x-ui.empty-state :title="__('Nothing out of stock')" :description="__('No items match the filters.')" />
        @else
            {{-- Mobile card list --}}
            <div class="space-y-3 md:hidden" data-cards>
                @foreach($rows as $r)
                    <x-ui.card wire:key="oos-c-{{ $r['item_id'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('items.show', $r['item_id']) }}" class="block truncate font-medium text-slate-900">{{ $r['name'] }}</a>
                                <p class="text-xs text-slate-500">{{ $r['sku'] ?? '—' }} &middot; {{ $r['group'] ?? __('No group') }}</p>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <x-ui.badge :variant="$abcBadge[$r['abc']]">{{ $r['abc'] }}</x-ui.badge>
                                <x-ui.badge :variant="$r['reason'] === 'missing' ? 'warning' : 'danger'">{{ $r['reason'] === 'missing' ? __('Missing') : __('Zero') }}</x-ui.badge>
                            </div>
                        </div>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                            <dt class="text-slate-500">{{ __('Days out') }}</dt><dd class="text-right font-medium tabular-nums text-slate-900">{{ $r['days_out'] }}</dd>
                            <dt class="text-slate-500">{{ __('Last quantity') }}</dt><dd class="text-right tabular-nums {{ $r['negative'] ? 'font-semibold text-red-700' : '' }}">{{ $fmt($r['last_qty']) }}</dd>
                            <dt class="text-slate-500">{{ __('Last in stock') }}</dt><dd class="text-right tabular-nums">{{ $r['in_stock_date'] }}</dd>
                            <dt class="text-slate-500">{{ __('Sales per day') }}</dt><dd class="text-right tabular-nums">{{ $fmt($r['speed']) }}</dd>
                            <dt class="text-slate-500">{{ __('Shops needing it') }}</dt><dd class="text-right tabular-nums">{{ $r['shops'] }} / {{ $fmt($r['need']) }}</dd>
                            <dt class="text-slate-500">{{ __('On open orders') }}</dt><dd class="text-right tabular-nums">{{ $fmt($r['open_orders']) }}</dd>
                            <dt class="text-slate-500">{{ __('Min / max') }}</dt><dd class="text-right tabular-nums">{{ $fmt($r['min']) }} / {{ $fmt($r['max']) }}</dd>
                        </dl>
                    </x-ui.card>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm md:block" data-table>
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left">
                        <tr>
                            @foreach([['name', __('Item')], [null, __('Group')], [null, __('ABC')], [null, __('Reason')], [null, __('Last qty')], [null, __('Last in stock')], ['days', __('Days out')], ['speed', __('Sales / day')], ['shops', __('Shops needing')], ['need', __('Need')], [null, __('Open orders')], [null, __('Min / max')]] as [$col, $label])
                                <th scope="col" class="{{ $th }}" @if($col && $sort === $col) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                    @if($col)
                                        <button type="button" wire:click="sortBy('{{ $col }}')" class="uppercase tracking-wide hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-500">{{ $label }}@if($sort === $col) {{ $dir === 'asc' ? '▲' : '▼' }}@endif</button>
                                    @else
                                        {{ $label }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($rows as $r)
                            <tr wire:key="oos-r-{{ $r['item_id'] }}">
                                <td class="px-3 py-2">
                                    <a href="{{ route('items.show', $r['item_id']) }}" class="font-medium text-slate-900 hover:underline">{{ $r['name'] }}</a>
                                    <p class="text-xs text-slate-500">{{ $r['sku'] ?? '—' }}</p>
                                </td>
                                <td class="px-3 py-2 text-slate-600">{{ $r['group'] ?? '—' }}</td>
                                <td class="px-3 py-2"><x-ui.badge :variant="$abcBadge[$r['abc']]">{{ $r['abc'] }}</x-ui.badge></td>
                                <td class="px-3 py-2"><x-ui.badge :variant="$r['reason'] === 'missing' ? 'warning' : 'danger'">{{ $r['reason'] === 'missing' ? __('Missing') : __('Zero') }}</x-ui.badge></td>
                                <td class="px-3 py-2 tabular-nums {{ $r['negative'] ? 'font-semibold text-red-700' : 'text-slate-600' }}" @if($r['negative']) title="{{ __('Negative quantity: check the data in 1C.') }}" @endif>{{ $fmt($r['last_qty']) }}</td>
                                <td class="px-3 py-2 tabular-nums text-slate-600">{{ $r['in_stock_date'] }}</td>
                                <td class="px-3 py-2 font-medium tabular-nums text-slate-900">{{ $r['days_out'] }}</td>
                                <td class="px-3 py-2 tabular-nums text-slate-600">{{ $fmt($r['speed']) }}</td>
                                <td class="px-3 py-2 tabular-nums {{ $r['shops'] > 0 ? 'font-semibold text-red-700' : 'text-slate-400' }}">{{ $r['shops'] }}</td>
                                <td class="px-3 py-2 tabular-nums text-slate-600">{{ $fmt($r['need']) }}</td>
                                <td class="px-3 py-2 tabular-nums text-slate-600">{{ $fmt($r['open_orders']) }}</td>
                                <td class="px-3 py-2 tabular-nums text-slate-600">{{ $fmt($r['min']) }} / {{ $fmt($r['max']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $rows->links() }}</div>
        @endif

        <p class="mt-4 text-xs text-slate-500" data-speed-note>{{ __('Sales per day is the chain average of the last 7 days and can be understated when the shops were also out of stock.') }}</p>
    @endif
</div>
