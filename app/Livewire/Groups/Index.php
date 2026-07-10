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

    public bool $showModal = false;

    public ?int $groupId = null;

    public string $name = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Group $group): void
    {
        $this->groupId = $group->id;
        $this->name = $group->name;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        Group::query()->updateOrCreate(['id' => $this->groupId], $data);

        $this->closeModal();
    }

    public function delete(Group $group): void
    {
        $group->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['groupId', 'name']);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.groups.index', [
            'groups' => Group::query()->orderBy('name')->paginate(10),
        ]);
    }
}
