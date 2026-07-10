<?php

namespace App\Livewire\Prices;

use App\Models\Price;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Prices'])]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.prices.index', [
            'prices' => Price::query()->orderBy('name')->paginate(10),
        ]);
    }
}
