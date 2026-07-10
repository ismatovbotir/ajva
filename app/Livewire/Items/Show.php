<?php

namespace App\Livewire\Items;

use App\Models\Item;
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
            'orderRules' => $this->item->orderRules()->with('shop')->orderBy('id')->get(),
            'stocks' => $this->item->stocks()->with('shop')->orderBy('id')->get(),
        ]);
    }
}
