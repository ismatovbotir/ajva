<div x-data="{ tab: 'barcodes' }">
    <x-ui.page-header :title="$item->name" :subtitle="($item->mark ?? '—').' · '.($item->group?->name ?? __('No group'))">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('items.index')">{{ __('Back to items') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Tab switcher --}}
    <div class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200">
        <button
            type="button"
            @click="tab = 'barcodes'"
            :class="tab === 'barcodes' ? 'border-accent text-accent' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Barcodes') }}</button>
        <button
            type="button"
            @click="tab = 'prices'"
            :class="tab === 'prices' ? 'border-accent text-accent' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Item prices') }}</button>
        <button
            type="button"
            @click="tab = 'orderRules'"
            :class="tab === 'orderRules' ? 'border-accent text-accent' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Item order rules') }}</button>
        <button
            type="button"
            @click="tab = 'stock'"
            :class="tab === 'stock' ? 'border-accent text-accent' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Stock') }}</button>
    </div>

    {{-- Barcodes tab --}}
    <div x-show="tab === 'barcodes'" x-cloak>
        @if($barcodes->isEmpty())
            <x-ui.empty-state :title="__('No barcodes yet')" :description="__('This item has no barcodes.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('GTIN') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($barcodes as $barcode)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $barcode->gtin }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>

    {{-- Item prices tab --}}
    <div x-show="tab === 'prices'" x-cloak>
        @if($itemPrices->isEmpty())
            <x-ui.empty-state :title="__('No item prices yet')" :description="__('This item has no prices.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Price') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Value') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($itemPrices as $itemPrice)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $itemPrice->price->name }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $itemPrice->value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>

    {{-- Order rules tab --}}
    <div x-show="tab === 'orderRules'" x-cloak>
        @if($orderRules->isEmpty())
            <x-ui.empty-state :title="__('No order rules yet')" :description="__('This item has no min/max order rules.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Min') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Max') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($orderRules as $orderRule)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $orderRule->shop->name }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $orderRule->min }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $orderRule->max }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>

    {{-- Stock tab --}}
    <div x-show="tab === 'stock'" x-cloak>
        @if($stocks->isEmpty())
            <x-ui.empty-state :title="__('No stock records yet')" :description="__('This item has no stock records.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($stocks as $stock)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $stock->shop->name }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $stock->qty }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>
</div>
