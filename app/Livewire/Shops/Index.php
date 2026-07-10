<?php

namespace App\Livewire\Shops;

use App\Models\Shop;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Shops'])]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.shops.index', [
            'shops' => Shop::query()->orderBy('name')->paginate(10),
        ]);
    }
}
