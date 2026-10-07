<?php

namespace App\Livewire\Items;

use App\Models\Item;
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
            'itemPrices' => $this->item->itemPrices()->with('price')->orderBy('id')->get(),
            'orderRules' => ShopAccess::restrict($this->item->orderRules(), 'item_order_rules.shop_id')->with('shop')->orderBy('id')->get(),
            'stocks' => ShopAccess::restrict($this->item->currentStocks(), 'stocks.shop_id')->with('shop')->orderBy('id')->get(),
        ]);
    }
}
