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

    public bool $showModal = false;

    public ?int $shopId = null;

    public string $name = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Shop $shop): void
    {
        $this->shopId = $shop->id;
        $this->name = $shop->name;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        Shop::query()->updateOrCreate(['id' => $this->shopId], $data);

        $this->closeModal();
    }

    public function delete(Shop $shop): void
    {
        $shop->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['shopId', 'name']);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.shops.index', [
            'shops' => Shop::query()->orderBy('name')->paginate(10),
        ]);
    }
}
