<div>
    <x-ui.page-header :title="__('Receipts')" :subtitle="__('Sales receipts ingested from POS terminals.')" />

    @if($noShops)
        <div class="mb-6"><x-ui.no-shops /></div>
    @endif

    {{-- Day selector --}}
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div class="w-full sm:w-56">
            <x-ui.label for="date">{{ __('Date') }}</x-ui.label>
            <input type="date" id="date" wire:model.live="date" max="{{ now()->toDateString() }}"
                   class="block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
        </div>
        <div class="flex gap-6 text-sm text-slate-500">
            <span>{{ __('Receipts') }}: <strong class="text-slate-900">{{ $summary['count'] }}</strong></span>
            <span>{{ __('Total sales') }}: <strong class="text-slate-900">{{ number_format($summary['total'], 0, '.', ' ') }}</strong></span>
        </div>
    </div>

    <div class="mb-6 space-y-6">
        {{-- Shop totals by payment type --}}
        <x-ui.card>
            <h2 class="text-base font-semibold text-slate-900">{{ __('Shop totals by payment type') }}</h2>
            <p class="mb-4 text-sm text-slate-500">{{ __('Net sales per shop for the selected day.') }}</p>

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
            <h2 class="text-base font-semibold text-slate-900">{{ __('Sales by hour') }}</h2>
            <p class="mb-4 text-sm text-slate-500">{{ __('Net sales per hour, split by shop.') }} {{ __('Each point is a receipt, joined by a line per shop (successful sales only).') }}</p>

            @if($summary['count'] === 0)
                <x-ui.empty-state :title="__('No sales for this day')" :description="__('Pick another date to see its sales.')" />
            @else
                <ul class="mb-4 flex flex-wrap gap-x-4 gap-y-1.5">
                    @foreach($legend as $item)
                        <li class="flex items-center gap-1.5 text-xs">
                            <span class="h-0 w-4 shrink-0 border-t-[3px]" style="border-color: {{ $item['color'] }}"></span>
                            <span class="text-slate-500">{{ $item['name'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <div x-data="{ mode: 'receipt' }">
                <div class="mb-3 inline-flex rounded-lg border border-slate-300 p-0.5 text-xs">
                    <button type="button" @click="mode = 'receipt'" :class="mode === 'receipt' ? 'bg-brand-700 text-white' : 'text-slate-600'" class="rounded-md px-3 py-1 font-medium">{{ __('Per receipt') }}</button>
                    <button type="button" @click="mode = 'hour'" :class="mode === 'hour' ? 'bg-brand-700 text-white' : 'text-slate-600'" class="rounded-md px-3 py-1 font-medium">{{ __('Per hour') }}</button>
                </div>
                <div class="overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                    <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" class="h-auto min-w-[34rem] w-full" role="img"
                         aria-label="{{ __('Sales by hour') }}">
                        {{-- Gridlines and y-axis labels (own scale per view) --}}
                        <g x-show="mode === 'receipt'">
                        @foreach($chart['receiptTicks'] as $tick)
                            <line x1="{{ $chart['left'] }}" x2="{{ $chart['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="#e1e0d9" stroke-width="1" />
                            <text x="{{ $chart['left'] - 8 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end" font-size="11" fill="#52514e">{{ $tick['label'] }}</text>
                        @endforeach
                        </g>
                        <g x-show="mode === 'hour'" x-cloak>
                        @foreach($chart['ticks'] as $tick)
                            <line x1="{{ $chart['left'] }}" x2="{{ $chart['right'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="#e1e0d9" stroke-width="1" />
                            <text x="{{ $chart['left'] - 8 }}" y="{{ $tick['y'] + 4 }}" text-anchor="end" font-size="11" fill="#52514e">{{ $tick['label'] }}</text>
                        @endforeach
                        </g>

                        {{-- x-axis hour labels --}}
                        @foreach($chart['xLabels'] as $label)
                            <text x="{{ $label['x'] }}" y="{{ $chart['baseline'] + 18 }}" text-anchor="middle" font-size="11" fill="#52514e">{{ $label['label'] }}</text>
                        @endforeach

                        {{-- Per receipt: a line through each shop's receipts, with one point per receipt --}}
                        <g x-show="mode === 'receipt'">
                        @foreach($chart['receiptLines'] as $line)
                            <polyline points="{{ $line['points'] }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="1.5" stroke-opacity="0.75" stroke-linejoin="round" stroke-linecap="round" />
                        @endforeach
                        @foreach($chart['receiptDots'] as $dot)
                            <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="2.5" fill="{{ $dot['color'] }}" fill-opacity="0.55"><title>{{ $dot['tip'] }}</title></circle>
                        @endforeach
                        </g>

                        {{-- Per hour: one line per shop --}}
                        <g x-show="mode === 'hour'" x-cloak>
                        @foreach($chart['lines'] as $line)
                            <polyline points="{{ $line['points'] }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                            @foreach($line['dots'] as $dot)
                                <circle cx="{{ $dot['x'] }}" cy="{{ $dot['y'] }}" r="4" fill="#fcfcfb" stroke="{{ $line['color'] }}" stroke-width="2" tabindex="0">
                                    <title>{{ $dot['tip'] }}</title>
                                </circle>
                            @endforeach
                        @endforeach
                        </g>
                    </svg>
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
