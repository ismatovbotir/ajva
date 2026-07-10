<?php

namespace App\Livewire\Items;

use App\Models\Category;
use App\Models\Group;
use App\Models\Item;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Items'])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $groupFilter = '';

    public bool $showModal = false;

    public ?int $itemId = null;

    public string $name = '';

    public ?string $mark = null;

    public ?int $group_id = null;

    public ?int $category_id = null;

    public ?string $class_code = null;

    public ?string $package_code = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGroupFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Item $item): void
    {
        $this->itemId = $item->id;
        $this->name = $item->name;
        $this->mark = $item->mark;
        $this->group_id = $item->group_id;
        $this->category_id = $item->category_id;
        $this->class_code = $item->class_code;
        $this->package_code = $item->package_code;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'mark' => ['nullable', 'string', 'max:20'],
            'group_id' => ['nullable', 'exists:groups,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'class_code' => ['nullable', 'string', 'max:25'],
            'package_code' => ['nullable', 'string', 'max:7'],
        ]);

        Item::query()->updateOrCreate(['id' => $this->itemId], $data);

        $this->closeModal();
    }

    public function delete(Item $item): void
    {
        $item->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['itemId', 'name', 'mark', 'group_id', 'category_id', 'class_code', 'package_code']);
        $this->resetErrorBag();
    }

    public function render()
    {
        $items = Item::query()
            ->with(['group', 'category'])
            ->when($this->search, function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', "%{$this->search}%")
                        ->orWhere('mark', 'like', "%{$this->search}%");
                });
            })
            ->when($this->groupFilter, fn ($query) => $query->where('group_id', $this->groupFilter))
            ->orderBy('name')
            ->paginate(10);

        return view('livewire.items.index', [
            'items' => $items,
            'groups' => Group::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }
}
