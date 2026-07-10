<?php

namespace App\Livewire\Groups;

use App\Models\Group;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Groups'])]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.groups.index', [
            'groups' => Group::query()->orderBy('name')->paginate(10),
        ]);
    }
}
