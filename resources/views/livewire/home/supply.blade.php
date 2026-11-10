<section wire:poll.120s class="space-y-3" aria-labelledby="home-supply-title" data-supply>
    <div class="flex flex-wrap items-end justify-between gap-2">
        <h2 id="home-supply-title" class="text-lg font-semibold text-slate-900">{{ $admin ? __('Supply') : __('Orders and stock in your shops') }}</h2>
        <p class="text-xs text-slate-500">{{ __('as of :time', ['time' => $checkedAt]) }} <span wire:loading style="display: none;" class="ml-1 text-primary-700">{{ __('Updating...') }}</span></p>
    </div>

    @if ($admin)
        @php $wh = $facts['wh'] ?? ['configured' => false]; @endphp
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @if ($wh['configured'])
                <x-home.tile :label="__('Out of stock at the warehouse')" :value="$wh['state'] === 'ok' ? $wh['out'] : '—'" :href="route('warehouse.out-of-stock')" :as-of="$checkedAt" :tone="$wh['out'] > 0 ? 'bad' : 'default'" data-tile="wh-out">
                    @if ($wh['state'] === 'not_synced')
                        <x-ui.badge variant="danger">{{ __('Warehouse not synced') }}</x-ui.badge>
                    @elseif ($wh['a'] > 0)
                        <x-ui.badge variant="danger">{{ __(':count of class A', ['count' => $wh['a']]) }}</x-ui.badge>
                    @endif
                </x-home.tile>
                <x-home.tile :label="__('Pick list coverage')" :value="$wh['coverage'] === null ? '—' : number_format($wh['coverage'], 0).'%'" :href="route('ordering.index')" :as-of="$checkedAt" :tone="$wh['coverage'] !== null && $wh['coverage'] < 70 ? 'bad' : ($wh['coverage'] !== null && $wh['coverage'] < 95 ? 'warn' : 'default')" data-tile="coverage">
                    @if ($wh['starved'] > 0)
                        <x-ui.badge variant="danger">{{ __(':count shop lines without stock', ['count' => $wh['starved']]) }}</x-ui.badge>
                    @endif
                </x-home.tile>
            @else
                <x-home.tile :label="__('Warehouse')" value="—" :href="route('settings.warehouse')" data-tile="wh-missing">
                    <x-ui.badge variant="warning">{{ __('Main warehouse is not set') }}</x-ui.badge>
                </x-home.tile>
            @endif
            <x-home.tile :label="__('Drafts waiting')" :value="$facts['drafts'] ?? 0" :href="route('monitoring.unconfirmed-orders', ['type' => 'draft'])" :as-of="$checkedAt" :tone="($facts['drafts_late'] ?? 0) > 0 ? 'warn' : 'default'" data-tile="drafts">
                @if (($facts['drafts_late'] ?? 0) > 0)
                    <x-ui.badge variant="warning">{{ __(':count late', ['count' => $facts['drafts_late']]) }}</x-ui.badge>
                @endif
            </x-home.tile>
            <x-home.tile :label="__('Failed 1C syncs')" :value="$facts['failed_sync'] ?? 0" :href="route('monitoring.unconfirmed-orders', ['type' => 'sync'])" :as-of="$checkedAt" :tone="($facts['failed_sync'] ?? 0) > 0 ? 'bad' : 'default'" data-tile="failed-sync" />
        </div>
    @else
        @if ($noShops)
            <x-ui.no-shops />
        @else
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <x-home.tile :label="__('Drafts to confirm')" :value="$facts['drafts'] ?? 0" :href="route('orders.index')" :as-of="$checkedAt" :tone="($facts['drafts_late'] ?? 0) > 0 ? 'warn' : 'default'" data-tile="drafts">
                    <span class="text-sm font-medium text-primary-700">{{ __('Open orders') }} →</span>
                </x-home.tile>

                <x-ui.card class="lg:col-span-2" data-block="low-items">
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('Out of stock or below minimum') }}</h3>
                    <p class="mb-2 text-xs text-slate-500">{{ __('The 10 most urgent items in your shops, from the latest 1C sync.') }} {{ __('as of :time', ['time' => $checkedAt]) }}</p>
                    @if ($lowItems === [])
                        <p class="text-sm text-slate-500">{{ __('No reorder risks right now') }}</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($lowItems as $row)
                                <li>
                                    <a href="{{ route('items.show', $row['item_id']) }}" class="flex items-center gap-2 py-2 text-sm hover:bg-slate-50">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-slate-900">{{ $row['item'] }}</span>
                                            <span class="block truncate text-xs text-slate-500">{{ $row['shop'] }}</span>
                                        </span>
                                        <span class="tabular-nums text-slate-600">{{ (float) $row['qty'] + 0 }} / {{ (float) $row['min'] + 0 }}</span>
                                        <span class="w-24 text-right text-xs font-medium {{ $row['out'] ? 'text-red-700' : 'text-amber-700' }}">{{ $row['out'] ? '● '.__('Out of stock') : '▲ '.__('Low') }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </div>
        @endif
    @endif
</section>
