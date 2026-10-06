<?php

namespace App\Livewire\Pos;

use App\Models\Pos;
use App\Models\Shop;
use App\Support\ShopAccess;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Pos'])]
class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?int $posId = null;

    public string $name = '';

    public ?int $shop_id = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Pos $pos): void
    {
        ShopAccess::authorize($pos->shop_id);

        $this->posId = $pos->id;
        $this->name = $pos->name;
        $this->shop_id = $pos->shop_id;
        $this->showModal = true;
    }

    public function save(): void
    {
        // Operators work only inside their own shops (and must pick one).
        $shopRule = ShopAccess::restricted()
            ? ['required', Rule::in(ShopAccess::ids())]
            : ['nullable', 'exists:shops,id'];

        $data = $this->validate([
            'name' => ['required', 'string', 'max:50'],
            'shop_id' => $shopRule,
        ]);

        if ($this->posId !== null) {
            ShopAccess::authorize(Pos::query()->findOrFail($this->posId)->shop_id);
        }

        Pos::query()->updateOrCreate(['id' => $this->posId], $data);

        $this->closeModal();
    }

    public function delete(Pos $pos): void
    {
        ShopAccess::authorize($pos->shop_id);

        $pos->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['posId', 'name', 'shop_id']);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.pos.index', [
            'poses' => ShopAccess::restrict(Pos::query(), 'pos.shop_id')->with('shop')->orderBy('name')->paginate(10),
            'shops' => ShopAccess::restrict(Shop::query(), 'shops.id')->orderBy('name')->get(),
            'noShops' => ShopAccess::hasNone(),
        ]);
    }
}
