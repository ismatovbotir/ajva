<?php

namespace App\Livewire\Items;

use App\Models\Category;
use App\Models\Group;
use App\Models\Item;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Items'])]
class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $groupFilter = '';

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
            ->get();

        return view('livewire.items.index', [
            'items' => $items,
            'groups' => Group::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }
}
