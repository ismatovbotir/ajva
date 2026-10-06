<div>
    <x-ui.page-header :title="__('Receipts')" :subtitle="__('Sales receipts ingested from POS terminals.')" />

    {{-- Day selector --}}
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div class="w-full sm:w-56">
            <x-ui.label for="date">{{ __('Date') }}</x-ui.label>
            <input type="date" id="date" wire:model.live="date" max="{{ now()->toDateString() }}"
                   class="block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
        </div>
        <div class="flex gap-6 text-sm text-[#52514e]">
            <span>{{ __('Receipts') }}: <strong class="text-[#0b0b0b]">{{ $summary['count'] }}</strong></span>
            <span>{{ __('Total sales') }}: <strong class="text-[#0b0b0b]">{{ number_format($summary['total'], 0, '.', ' ') }}</strong></span>
        </div>
    </div>

    <div class="mb-6 space-y-6">
        {{-- Shop totals by payment type --}}
        <x-ui.card>
            <h2 class="text-base font-semibold text-[#0b0b0b]">{{ __('Shop totals by payment type') }}</h2>
            <p class="mb-4 text-sm text-[#52514e]">{{ __('Net sales per shop for the selected day.') }}</p>

            @if(empty($paymentTable))
                <x-ui.empty-state :title="__('No sales for this day')" :description="__('Pick another date to see its sales.')" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-sand-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                                @foreach($paymentTypes as $type)
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $type }}</th>
                                @endforeach
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($paymentTable as $row)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['name'] }}</td>
                                    @foreach($row['cells'] as $value)
                                        <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ number_format($value, 0, '.', ' ') }}</td>
                                    @endforeach
                                    <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-700">{{ number_format($row['total'], 0, '.', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-sand-50">
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ __('Total') }}</td>
                                @foreach($columnTotals as $value)
                                    <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-900">{{ number_format($value, 0, '.', ' ') }}</td>
                                @endforeach
                                <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums text-slate-900">{{ number_format($grandTotal, 0, '.', ' ') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- Shop sales by hour --}}
        <x-ui.card>
            <h2 class="text-base font-semibold text-[#0b0b0b]">{{ __('Sales by hour') }}</h2>
            <p class="mb-4 text-sm text-[#52514e]">{{ __('Net sales per hour, split by shop.') }}</p>

            @if($summary['count'] === 0)
                <x-ui.empty-state :title="__('No sales for this day')" :description="__('Pick another date to see its sales.')" />
            @else
                <ul class="mb-4 flex flex-wrap gap-x-4 gap-y-1.5">
                    @foreach($legend as $item)
                        <li class="flex items-center gap-1.5 text-xs">
                            <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $item['color'] }}"></span>
                            <span class="text-[#52514e]">{{ $item['name'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <div x-data="{ tip: { show: false, x: 0, y: 0, name: '', value: '' } }"
                     class="relative overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                    <div class="flex h-56 min-w-[34rem] items-end gap-1">
                        @foreach($hours as $h)
                            <div class="flex h-full flex-1 flex-col justify-end" title="{{ $h['hour'] }}:00 — {{ $h['total_label'] }}">
                                <div class="flex flex-col-reverse overflow-hidden rounded-t-[3px]" style="height: {{ array_sum(array_column($h['segments'], 'percent')) }}%;">
                                    @foreach($h['segments'] as $seg)
                                        <div tabindex="0"
                                             @pointermove="tip = { show: true, x: $event.clientX - $root.getBoundingClientRect().left, y: $event.clientY - $root.getBoundingClientRect().top, name: @js($seg['name'] . ' · ' . $h['hour'] . ':00'), value: @js($seg['label']) }"
                                             @pointerleave="tip.show = false"
                                             @focus="tip = { show: true, x: $el.getBoundingClientRect().left - $root.getBoundingClientRect().left, y: $el.getBoundingClientRect().top - $root.getBoundingClientRect().top, name: @js($seg['name'] . ' · ' . $h['hour'] . ':00'), value: @js($seg['label']) }"
                                             @blur="tip.show = false"
                                             class="w-full transition hover:brightness-90 focus:outline-none"
                                             style="height: {{ $h['total'] > 0 ? $seg['percent'] / array_sum(array_column($h['segments'], 'percent')) * 100 : 0 }}%; background-color: {{ $seg['color'] }};"></div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex min-w-[34rem] gap-1">
                        @foreach($hours as $h)
                            <span class="flex-1 text-center text-[10px] text-[#52514e]">{{ $h['hour'] }}</span>
                        @endforeach
                    </div>

                    <div x-show="tip.show" x-cloak
                         class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                         :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`">
                        <p x-text="tip.name" class="font-medium"></p>
                        <p x-text="tip.value"></p>
                    </div>
                </div>
            @endif
        </x-ui.card>
    </div>

    @if($receipts->isEmpty())
        <x-ui.empty-state :title="__('No receipts for this day')" :description="__('Receipts will appear here once POS terminals start sending them.')" />
    @else
        {{-- Mobile card list --}}
        <div class="max-h-[70vh] space-y-3 overflow-y-auto md:hidden">
            @foreach($receipts as $receipt)
                <a href="{{ route('receipts.show', $receipt) }}" class="block">
                    <x-ui.card>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-slate-900">{{ $receipt->number }} <span class="ml-1 text-xs font-normal tabular-nums text-slate-500">{{ $receipt->created_at->format('H:i:s') }}</span></p>
                                <p class="text-sm text-slate-500">{{ $receipt->shop->name }} &middot; {{ $receipt->pos->name }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-slate-900">{{ number_format((float) $receipt->total, 2) }}</p>
                                <div class="mt-1 flex gap-1">
                                    <x-ui.badge :variant="$receipt->active ? 'success' : 'danger'">{{ $receipt->active ? __('Active') : __('Inactive') }}</x-ui.badge>
                                    <x-ui.badge :variant="$receipt->sell ? 'info' : 'warning'">{{ $receipt->sell ? __('Sell') : __('Refund') }}</x-ui.badge>
                                </div>
                            </div>
                        </div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <div class="max-h-[70vh] overflow-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="sticky top-0 z-10 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Time') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Number') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Pos') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($receipts as $receipt)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-sm tabular-nums text-slate-500">{{ $receipt->created_at->format('H:i:s') }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $receipt->number }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $receipt->shop->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $receipt->pos->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $receipt->total, 2) }}</td>
                            <td class="px-4 py-3 text-sm">
                                <x-ui.badge :variant="$receipt->active ? 'success' : 'danger'">{{ $receipt->active ? __('Active') : __('Inactive') }}</x-ui.badge>
                                <x-ui.badge :variant="$receipt->sell ? 'info' : 'warning'">{{ $receipt->sell ? __('Sell') : __('Refund') }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" :href="route('receipts.show', $receipt)">{{ __('View') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-ui.card>
    @endif
</div>
