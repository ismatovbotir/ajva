<?php

namespace App\Livewire\Items;

use App\Models\Item;
use App\Support\ProfitAccess;
use App\Support\ShopAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Item'])]
class Show extends Component
{
    public Item $item;

    public function mount(Item $item): void
    {
        $this->item = $item;
    }

    public function render()
    {
        $current = ShopAccess::restrict($this->item->currentStocks(), 'stocks.shop_id')->get()->keyBy('shop_id');
        // Explicit zero: a shop whose latest 1C sync did not report this item shows "Not reported", not the old quantity.
        $latest = \App\Models\Stock::latestDates($current->pluck('shop_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        // Every active (not disabled) shop is listed, also with 0 or without any stock record yet.
        $stocks = ShopAccess::restrict(\App\Models\Shop::query()->enabled(), 'shops.id')->orderBy('name')->get()
            ->map(function ($shop) use ($current, $latest) {
                $row = $current->get($shop->id);

                return (object) [
                    'shop_id' => (int) $shop->id,
                    'shop' => $shop,
                    'qty' => $row?->qty,
                    'stock_date' => $row?->stock_date,
                    'reported' => $row !== null && \App\Models\Stock::isReported((string) $row->stock_date, $latest[(int) $shop->id] ?? null),
                ];
            });

        return view('livewire.items.show', [
            'barcodes' => $this->item->barcodes()->orderBy('id')->get(),
            'itemPrices' => $this->item->itemPrices()
                // The cost price is profit data: only for users with the permission.
                ->when(! ProfitAccess::allowed(), fn ($q) => $q->where('item_prices.price_id', '<>', (int) config('inventory.cost_price_id')))
                ->with('price')->orderBy('id')->get(),
            'orderRules' => ShopAccess::restrict($this->item->orderRules(), 'item_order_rules.shop_id')->with('shop')->orderBy('id')->get(),
            'stocks' => $stocks,
        ]);
    }
}
