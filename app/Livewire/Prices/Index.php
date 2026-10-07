<?php

namespace App\Livewire\Prices;

use App\Models\Price;
use App\Support\ProfitAccess;
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
            'prices' => Price::query()
                ->when(! ProfitAccess::allowed(), fn ($q) => $q->where('prices.id', '<>', (int) config('inventory.cost_price_id')))
                ->orderBy('name')->paginate(10),
        ]);
    }
}
