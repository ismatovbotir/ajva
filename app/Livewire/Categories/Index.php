<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Categories'])]
class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?int $categoryId = null;

    public string $name = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Category $category): void
    {
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        Category::query()->updateOrCreate(['id' => $this->categoryId], $data);

        $this->closeModal();
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['categoryId', 'name']);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.categories.index', [
            'categories' => Category::query()->orderBy('name')->paginate(10),
        ]);
    }
}
