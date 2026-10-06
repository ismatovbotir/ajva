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

    private const PER_PAGE = 50;

    #[Url]
    public string $search = '';

    #[Url]
    public string $groupFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGroupFilter(): void
    {
        $this->resetPage();
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
            ->paginate(self::PER_PAGE);

        return view('livewire.items.index', [
            'items' => $items,
            'groups' => Group::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }
}
