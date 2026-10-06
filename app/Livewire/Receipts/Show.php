<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
use App\Support\ShopAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Receipt'])]
class Show extends Component
{
    public Receipt $receipt;

    public function mount(Receipt $receipt): void
    {
        ShopAccess::authorize($receipt->shop_id);
        $this->receipt = $receipt;
    }

    public function hydrate(): void
    {
        ShopAccess::authorize($this->receipt->shop_id);
    }

    public function render()
    {
        return view('livewire.receipts.show', [
            'receipt' => $this->receipt->load(['pos', 'shop', 'items.item', 'payments']),
        ]);
    }
}
