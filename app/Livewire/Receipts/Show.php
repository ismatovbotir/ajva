<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Receipt'])]
class Show extends Component
{
    public Receipt $receipt;

    public function mount(Receipt $receipt): void
    {
        $this->receipt = $receipt;
    }

    public function render()
    {
        return view('livewire.receipts.show', [
            'receipt' => $this->receipt->load(['pos', 'shop', 'items.item', 'payments']),
        ]);
    }
}
