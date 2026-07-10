<?php

namespace App\Livewire\Stocks;

use App\Models\Item;
use App\Models\Shop;
use App\Models\Stock;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Stocks'])]
class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?int $stockId = null;

    public ?int $item_id = null;

    public ?int $shop_id = null;

    public string $qty = '0';

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Stock $stock): void
    {
        $this->stockId = $stock->id;
        $this->item_id = $stock->item_id;
        $this->shop_id = $stock->shop_id;
        $this->qty = (string) $stock->qty;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'item_id' => ['required', 'exists:items,id'],
            'shop_id' => [
                'required',
                'exists:shops,id',
                Stock::shopUniqueRule($this->item_id, $this->stockId),
            ],
            'qty' => ['required', 'numeric', 'min:0'],
        ], [
            'shop_id.unique' => __('Stock for this item and shop combination already exists.'),
        ]);

        Stock::query()->updateOrCreate(['id' => $this->stockId], $data);

        $this->closeModal();
    }

    public function delete(Stock $stock): void
    {
        $stock->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['stockId', 'item_id', 'shop_id']);
        $this->qty = '0';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.stocks.index', [
            'stocks' => Stock::query()->with(['item', 'shop'])->orderBy('id', 'desc')->paginate(10),
            'items' => Item::query()->orderBy('name')->get(),
            'shops' => Shop::query()->orderBy('name')->get(),
        ]);
    }
}
