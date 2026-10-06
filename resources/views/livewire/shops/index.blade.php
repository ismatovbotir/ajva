@php
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
@endphp
<div>
    <x-ui.page-header :title="__('Shops')" :subtitle="__('Shops synced from 1C, with their current stock.')" />

    @if($noShops)
        <x-ui.no-shops />
    @elseif($shops->isEmpty())
        <x-ui.empty-state :title="__('No shops yet')" :description="__('Shops will appear here once 1C sends stock data for them.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($shops as $shop)
                @php $t = $today[$shop->id] ?? null; @endphp
                <a href="{{ route('shops.show', $shop) }}" class="block">
                    <x-ui.card>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-slate-900">{{ $shop->name }}</p>
                                <p class="text-sm text-slate-500">#{{ $shop->id }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-slate-900">{{ number_format((float) $shop->qty_total) }} {{ __('qty') }}</p>
                                <p class="text-sm text-slate-500">{{ $shop->item_count }} {{ __('items') }}</p>
                            </div>
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-2 border-t border-slate-100 pt-3 text-sm">
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('Sell') }}</dt>
                                <dd class="font-medium tabular-nums">{{ (int) ($t->sell_count ?? 0) }}</dd>
                                <dd class="text-xs tabular-nums text-slate-500">{{ $money($t->sell_sum ?? 0) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('Refund') }}</dt>
                                <dd class="font-medium tabular-nums">{{ (int) ($t->refund_count ?? 0) }}</dd>
                                <dd class="text-xs tabular-nums text-slate-500">{{ $money($t->refund_sum ?? 0) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">{{ __('Cancel') }}</dt>
                                <dd class="font-medium tabular-nums">{{ (int) ($t->cancel_count ?? 0) }}</dd>
                            </div>
                        </dl>
                    </x-ui.card>
                </a>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ID') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Items') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total qty') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Sell today') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Sell sum') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Refund today') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Refund sum') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Cancel today') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($shops as $shop)
                        @php $t = $today[$shop->id] ?? null; @endphp
                        <tr class="cursor-pointer hover:bg-sand-50" onclick="window.location='{{ route('shops.show', $shop) }}'">
                            <td class="px-4 py-3 text-sm tabular-nums text-slate-500">{{ $shop->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                <a href="{{ route('shops.show', $shop) }}" class="hover:text-brand-700 hover:underline">{{ $shop->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $shop->item_count }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $shop->qty_total) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-700">{{ (int) ($t->sell_count ?? 0) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium tabular-nums text-slate-900">{{ $money($t->sell_sum ?? 0) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-700">{{ (int) ($t->refund_count ?? 0) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium tabular-nums text-slate-900">{{ $money($t->refund_sum ?? 0) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-700">{{ (int) ($t->cancel_count ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-ui.card>

        <div class="mt-4">{{ $shops->links() }}</div>
    @endif
</div>
