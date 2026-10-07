@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
@endphp
<div>
    <x-ui.page-header :title="__('Ordering')" :subtitle="__('Items each shop should order, from min/max rules and stock after net sales.')" />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">{{ __('Refresh') }}</x-ui.button>
        @if($report['generated_at'])
            <p class="text-sm text-slate-500">
                {{ __('Updated') }} {{ $report['generated_at'] }} ·
                {{ __(':count items to order', ['count' => $report['total_items']]) }}
            </p>
        @endif
    </div>

    <p class="mb-4 text-xs text-slate-500">
        {{ __('Estimated stock = stock minus (successful sales minus successful refunds) since the stock date. An item is listed when the estimated stock is at or below its minimum; the order is the maximum minus the estimated stock.') }}
    </p>

    @if($noShops)
        <x-ui.no-shops />
    @elseif($shops->isEmpty())
        <x-ui.empty-state :title="__('No shops yet')" :description="__('Shops will appear here once 1C sends stock data for them.')" />
    @else
        {{-- One tab per shop, each with its own order list --}}
        <div class="mb-4 overflow-x-auto border-b border-slate-200">
            <nav class="-mb-px flex gap-1" role="tablist">
                @foreach($shops as $shop)
                    <button type="button" role="tab" wire:key="ord-tab-{{ $shop['id'] }}" wire:click="selectShop({{ $shop['id'] }})"
                            aria-selected="{{ $shop['id'] === $shopId ? 'true' : 'false' }}"
                            class="flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition {{ $shop['id'] === $shopId ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                        {{ $shop['name'] }}
                        <x-ui.badge :variant="$shop['out'] > 0 ? 'danger' : ($shop['count'] > 0 ? 'warning' : 'default')">{{ $shop['count'] }}</x-ui.badge>
                    </button>
                @endforeach
            </nav>
        </div>

        @if(! $active || $active['count'] === 0)
            <x-ui.empty-state :title="__('Nothing to order')" :description="__('Every item of this shop is above its minimum.')" />
        @else
            {{-- Mobile card list --}}
            <div class="max-h-[65vh] space-y-3 overflow-y-auto md:hidden">
                @foreach($active['rows'] as $row)
                    <x-ui.card wire:key="ord-card-{{ $active['id'] }}-{{ $row['item_id'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                @if($row['group'])<p class="text-xs text-slate-500">{{ $row['group'] }}</p>@endif
                                <p class="font-medium text-slate-900">{{ $row['item'] }}</p>
                            </div>
                            <x-ui.badge :variant="$row['level'] === 'out' ? 'danger' : 'warning'">{{ $row['level'] === 'out' ? __('Out of stock') : __('Low') }}</x-ui.badge>
                        </div>
                        <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                            <div><dt class="text-xs text-slate-500">{{ __('Stock') }}</dt><dd class="tabular-nums">{{ $fmt($row['stock']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Net sold') }}</dt><dd class="tabular-nums">{{ $fmt($row['net_sold']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Estimated') }}</dt><dd class="font-semibold tabular-nums">{{ $fmt($row['estimated']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Min') }}</dt><dd class="tabular-nums">{{ $fmt($row['min']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Max') }}</dt><dd class="tabular-nums">{{ $fmt($row['max']) }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ __('Order') }}</dt><dd class="text-base font-bold tabular-nums text-brand-700">{{ $fmt($row['order']) }}</dd></div>
                        </dl>
                    </x-ui.card>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <x-ui.card padding="p-0" class="hidden md:block">
                <div class="max-h-[65vh] overflow-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="sticky top-0 z-10 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Stock') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Net sold') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Estimated') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Min') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Max') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Order') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($active['rows'] as $row)
                                <tr wire:key="ord-row-{{ $active['id'] }}-{{ $row['item_id'] }}">
                                    <td class="px-4 py-3 text-sm text-slate-500">{{ $row['group'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['item'] }}</td>
                                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['stock']) }}</td>
                                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['net_sold']) }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-700">{{ $fmt($row['estimated']) }}</td>
                                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['min']) }}</td>
                                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['max']) }}</td>
                                    <td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-brand-700">{{ $fmt($row['order']) }}</td>
                                    <td class="px-4 py-3 text-sm"><x-ui.badge :variant="$row['level'] === 'out' ? 'danger' : 'warning'">{{ $row['level'] === 'out' ? __('Out of stock') : __('Low') }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
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
