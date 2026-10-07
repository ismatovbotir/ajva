<?php

namespace App\Livewire\Shops;

use App\Models\Shop;
use App\Support\ShopAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Shop'])]
class Show extends Component
{
    public Shop $shop;

    public function mount(Shop $shop): void
    {
        ShopAccess::authorize($shop->id);
        $this->shop = $shop;
    }

    /** Livewire updates skip the route; re-check in case the assignment changed meanwhile. */
    public function hydrate(): void
    {
        ShopAccess::authorize($this->shop->id);
    }

    public function render()
    {
        return view('livewire.shops.show', [
            'stocks' => $this->shop->currentStocks()->with('item')->orderByDesc('qty')->get(),
        ]);
    }
}
