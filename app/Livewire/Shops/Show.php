<?php

namespace App\Livewire\Shops;

use App\Models\Shop;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Shop'])]
class Show extends Component
{
    public Shop $shop;

    public function mount(Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function render()
    {
        return view('livewire.shops.show', [
            'stocks' => $this->shop->stocks()->with('item')->orderByDesc('qty')->get(),
        ]);
    }
}
