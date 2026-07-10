<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Receipts'])]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.receipts.index', [
            'receipts' => Receipt::query()
                ->with(['pos', 'shop'])
                ->orderBy('id', 'desc')
                ->paginate(15),
        ]);
    }
}
