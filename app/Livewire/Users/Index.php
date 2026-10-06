<?php

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Users'])]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showModal = false;

    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $user = User::query()->findOrFail($id);

        $this->resetForm();
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'password' => [$this->userId ? 'nullable' : 'required', 'string', 'min:8'],
        ]);

        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        // The model's 'hashed' cast hashes the password; never hash here.
        if ($this->userId) {
            User::query()->findOrFail($this->userId)->update($data);
        } else {
            User::query()->create($data);
        }

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        if ($id === auth()->id()) {
            $this->addError('delete', __('You cannot delete your own account.'));

            return;
        }

        if (User::query()->count() <= 1) {
            $this->addError('delete', __('You cannot delete the last remaining user.'));

            return;
        }

        User::query()->whereKey($id)->delete();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => __('Name'),
            'email' => __('Email'),
            'password' => __('Password'),
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['userId', 'name', 'email', 'password']);
        $this->resetErrorBag();
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.users.index', ['users' => $users]);
    }
}
