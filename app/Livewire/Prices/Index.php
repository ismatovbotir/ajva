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

    public bool $showModal = false;

    public ?int $priceId = null;

    public string $name = '';

    public bool $is_sell = true;

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Price $price): void
    {
        $this->priceId = $price->id;
        $this->name = $price->name;
        $this->is_sell = $price->is_sell;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_sell' => ['boolean'],
        ]);

        Price::query()->updateOrCreate(['id' => $this->priceId], $data);

        $this->closeModal();
    }

    public function delete(Price $price): void
    {
        $price->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['priceId', 'name']);
        $this->is_sell = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.prices.index', [
            'prices' => Price::query()->orderBy('name')->paginate(10),
        ]);
    }
}
