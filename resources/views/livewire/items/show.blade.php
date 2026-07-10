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
            :class="tab === 'barcodes' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Barcodes') }}</button>
        <button
            type="button"
            @click="tab = 'prices'"
            :class="tab === 'prices' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Item prices') }}</button>
        <button
            type="button"
            @click="tab = 'orderRules'"
            :class="tab === 'orderRules' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Item order rules') }}</button>
        <button
            type="button"
            @click="tab = 'stock'"
            :class="tab === 'stock' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
            class="flex-shrink-0 border-b-2 px-4 py-2 text-sm font-medium"
        >{{ __('Stock') }}</button>
    </div>

    {{-- Barcodes tab --}}
    <div x-show="tab === 'barcodes'" x-cloak>
        <div class="mb-4 flex justify-end">
            <x-ui.button wire:click="createBarcode">{{ __('New barcode') }}</x-ui.button>
        </div>

        @if($barcodes->isEmpty())
            <x-ui.empty-state :title="__('No barcodes yet')" :description="__('Add a barcode for this item.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('GTIN') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($barcodes as $barcode)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $barcode->gtin }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <x-ui.button variant="secondary" wire:click="editBarcode({{ $barcode->id }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="deleteBarcode({{ $barcode->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>

    {{-- Item prices tab --}}
    <div x-show="tab === 'prices'" x-cloak>
        <div class="mb-4 flex justify-end">
            <x-ui.button wire:click="createItemPrice">{{ __('New item price') }}</x-ui.button>
        </div>

        @if($itemPrices->isEmpty())
            <x-ui.empty-state :title="__('No item prices yet')" :description="__('Add a price for this item.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Price') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Value') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($itemPrices as $itemPrice)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $itemPrice->price->name }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $itemPrice->value }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <x-ui.button variant="secondary" wire:click="editItemPrice({{ $itemPrice->id }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="deleteItemPrice({{ $itemPrice->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>

    {{-- Order rules tab --}}
    <div x-show="tab === 'orderRules'" x-cloak>
        <div class="mb-4 flex justify-end">
            <x-ui.button wire:click="createOrderRule">{{ __('New order rule') }}</x-ui.button>
        </div>

        @if($orderRules->isEmpty())
            <x-ui.empty-state :title="__('No order rules yet')" :description="__('Add a min/max order rule for this item.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Min') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Max') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($orderRules as $orderRule)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $orderRule->shop->name }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $orderRule->min }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $orderRule->max }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <x-ui.button variant="secondary" wire:click="editOrderRule({{ $orderRule->id }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="deleteOrderRule({{ $orderRule->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>

    {{-- Stock tab --}}
    <div x-show="tab === 'stock'" x-cloak>
        <div class="mb-4 flex justify-end">
            <x-ui.button wire:click="createStock">{{ __('New stock') }}</x-ui.button>
        </div>

        @if($stocks->isEmpty())
            <x-ui.empty-state :title="__('No stock records yet')" :description="__('Add a stock record for this item.')" />
        @else
            <x-ui.card padding="p-0">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-sand-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($stocks as $stock)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $stock->shop->name }}</td>
                                <td class="px-4 py-3 text-sm text-slate-500">{{ $stock->qty }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <x-ui.button variant="secondary" wire:click="editStock({{ $stock->id }})">{{ __('Edit') }}</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="deleteStock({{ $stock->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @endif
    </div>

    {{-- Barcode modal --}}
    <x-ui.modal :show="$showBarcodeModal" :title="$barcodeId ? __('Edit barcode') : __('New barcode')" :close="'closeBarcodeModal'">
        <form wire:submit="saveBarcode" class="space-y-4">
            <x-ui.input name="gtin" :label="__('GTIN')" wire:model="gtin" autofocus />

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeBarcodeModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Item price modal --}}
    <x-ui.modal :show="$showItemPriceModal" :title="$itemPriceId ? __('Edit item price') : __('New item price')" :close="'closeItemPriceModal'">
        <form wire:submit="saveItemPrice" class="space-y-4">
            <x-ui.select name="price_id" :label="__('Price')" wire:model="price_id">
                <option value="">{{ __('Select a price') }}</option>
                @foreach($prices as $price)
                    <option value="{{ $price->id }}">{{ $price->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input name="value" type="number" step="0.01" min="0" :label="__('Value')" wire:model="value" />

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeItemPriceModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Order rule modal --}}
    <x-ui.modal :show="$showOrderRuleModal" :title="$orderRuleId ? __('Edit order rule') : __('New order rule')" :close="'closeOrderRuleModal'">
        <form wire:submit="saveOrderRule" class="space-y-4">
            <x-ui.select name="shop_id" :label="__('Shop')" wire:model="shop_id">
                <option value="">{{ __('Select a shop') }}</option>
                @foreach($shops as $shop)
                    <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input name="min" type="number" step="0.001" min="0" :label="__('Min')" wire:model="min" />
            <x-ui.input name="max" type="number" step="0.001" min="0" :label="__('Max')" wire:model="max" />

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeOrderRuleModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Stock modal --}}
    <x-ui.modal :show="$showStockModal" :title="$stockId ? __('Edit stock') : __('New stock')" :close="'closeStockModal'">
        <form wire:submit="saveStock" class="space-y-4">
            <x-ui.select name="stock_shop_id" :label="__('Shop')" wire:model="stock_shop_id">
                <option value="">{{ __('Select a shop') }}</option>
                @foreach($shops as $shop)
                    <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input name="stock_qty" type="number" step="0.001" min="0" :label="__('Qty')" wire:model="stock_qty" />

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeStockModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
