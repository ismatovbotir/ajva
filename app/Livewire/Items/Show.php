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
        return view('livewire.items.show', [
            'barcodes' => $this->item->barcodes()->orderBy('id')->get(),
            'itemPrices' => $this->item->itemPrices()
                // The cost price is profit data: only for users with the permission.
                ->when(! ProfitAccess::allowed(), fn ($q) => $q->where('item_prices.price_id', '<>', (int) config('inventory.cost_price_id')))
                ->with('price')->orderBy('id')->get(),
            'orderRules' => ShopAccess::restrict($this->item->orderRules(), 'item_order_rules.shop_id')->with('shop')->orderBy('id')->get(),
            'stocks' => ShopAccess::restrict($this->item->currentStocks(), 'stocks.shop_id')->with('shop')->orderBy('id')->get(),
        ]);
    }
}
