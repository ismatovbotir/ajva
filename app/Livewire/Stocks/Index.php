<?php

namespace App\Livewire\Stocks;

use App\Models\Stock;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Stocks'])]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.stocks.index', [
            'stocks' => Stock::query()->with(['item', 'shop'])->orderBy('id', 'desc')->paginate(10),
        ]);
    }
}
