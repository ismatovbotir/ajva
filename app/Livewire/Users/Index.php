<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Models\Shop;
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

    public string $role = 'operator';

    public bool $canSeeProfit = false;

    /** @var array<int, int|string> Shops an operator may see (ignored for other roles). */
    public array $shopIds = [];

    /**
     * Runs on every request, including Livewire updates, which skip the route
     * middleware - so a demoted admin can't keep using an already-open page.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

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
        $this->role = $user->role->value;
        $this->canSeeProfit = (bool) $user->can_see_profit;
        $this->shopIds = $user->shops()->pluck('shops.id')->map(fn ($id) => (string) $id)->all();
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'password' => [$this->userId ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'canSeeProfit' => ['boolean'],
            'shopIds' => ['array'],
            'shopIds.*' => ['integer', 'exists:shops,id'],
        ]);

        // Shop access is a pivot, not a column; only operators carry it.
        $shopIds = $data['role'] === UserRole::Operator->value
            ? array_values(array_unique(array_map('intval', $data['shopIds'] ?? [])))
            : [];
        unset($data['shopIds']);

        // Admins always see profit, so their flag is neither editable nor stored from the form.
        if ($data['role'] !== UserRole::Admin->value) {
            $data['can_see_profit'] = (bool) $data['canSeeProfit'];
        }
        unset($data['canSeeProfit']);

        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        // The model's 'hashed' cast hashes the password; never hash here.
        if ($this->userId) {
            $user = User::query()->findOrFail($this->userId);

            // Nobody changes their own role (easy way to lock yourself out),
            // and the last admin can never be demoted.
            if ($user->role->value !== $data['role']) {
                if ($user->id === auth()->id()) {
                    $this->addError('role', __('You cannot change your own role.'));

                    return;
                }

                if ($user->isAdmin() && User::query()->where('role', UserRole::Admin->value)->count() <= 1) {
                    $this->addError('role', __('At least one admin is required.'));

                    return;
                }
            }

            $user->update($data);
        } else {
            $user = User::query()->create($data);
        }

        $user->shops()->sync($shopIds);

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

        $target = User::query()->findOrFail($id);
        if ($target->isAdmin() && User::query()->where('role', UserRole::Admin->value)->count() <= 1) {
            $this->addError('delete', __('You cannot delete the last admin.'));

            return;
        }

        $target->delete();
    }

    public function selectAllShops(): void
    {
        $this->shopIds = Shop::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearShops(): void
    {
        $this->shopIds = [];
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
            'role' => __('Role'),
            'shopIds' => __('Shops'),
            'canSeeProfit' => __('Can see profit'),
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['userId', 'name', 'email', 'password', 'role', 'shopIds', 'canSeeProfit']);
        $this->resetErrorBag();
    }

    public function render()
    {
        $users = User::query()
            ->with('shops:id,name')
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.users.index', [
            'users' => $users,
            'roles' => UserRole::cases(),
            'shops' => Shop::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
