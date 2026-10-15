<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Models\Shop;
use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Services\Telegram;
use App\Services\TelegramLinks;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    public string $telegramChatId = '';

    /** The open one-time Telegram connect code, its link (empty without TELEGRAM_BOT_USERNAME) and what happened to it. */
    #[Locked]
    public ?string $linkCode = null;

    #[Locked]
    public string $linkUrl = '';

    #[Locked]
    public bool $linkExpired = false;

    #[Locked]
    public bool $justLinked = false;

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
        $this->telegramChatId = (string) $user->telegram_chat_id;
        $this->shopIds = $user->shops()->pluck('shops.id')->map(fn ($id) => (string) $id)->all();
        $this->showModal = true;
    }

    public function save(): void
    {
        if ($this->persist() !== null) {
            $this->closeModal();
        }
    }

    /** Saves the user and keeps the modal open with the Telegram connect code (a new user needs to exist first). */
    public function saveAndConnect(): void
    {
        $user = $this->persist();
        if ($user !== null) {
            $this->userId = $user->id;
            $this->password = '';
            $this->issueCode($user);
        }
    }

    /** Creates / updates the user from the form; null when validation or a guard failed. */
    private function persist(): ?User
    {
        $this->syncTelegram();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'password' => [$this->userId ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'canSeeProfit' => ['boolean'],
            'telegramChatId' => ['nullable', 'regex:/^-?\d{1,39}$/', 'max:40'],
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

        $data['telegram_chat_id'] = trim((string) $data['telegramChatId']) === '' ? null : trim($data['telegramChatId']);
        unset($data['telegramChatId']);

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

                    return null;
                }

                if ($user->isAdmin() && User::query()->where('role', UserRole::Admin->value)->count() <= 1) {
                    $this->addError('role', __('At least one admin is required.'));

                    return null;
                }
            }

            $user->update($data);
        } else {
            $user = User::query()->create($data);
        }

        $user->shops()->sync($shopIds);

        return $user;
    }

    /** Activate / deactivate (confirmed in the UI). Not yourself, not the last active admin. History stays. */
    public function setActive(int $id, bool $active): void
    {
        $target = User::query()->findOrFail($id);

        if (! $active) {
            if ($target->id === auth()->id()) {
                $this->addError('status', __('You cannot deactivate your own account.'));

                return;
            }
            if ($target->isAdmin() && User::query()->where('role', UserRole::Admin->value)->where('is_active', true)->whereKeyNot($target->id)->doesntExist()) {
                $this->addError('status', __('You cannot deactivate the last active admin.'));

                return;
            }
        }

        $target->forceFill(['is_active' => $active])->save();
    }

    public function connectTelegram(): void
    {
        $user = $this->userId ? User::query()->find($this->userId) : null;
        if ($user === null) {
            return;
        }
        if (! $user->is_active) {
            $this->addError('telegram', __('Activate the user first.'));

            return;
        }
        $this->issueCode($user);
    }

    private function issueCode(User $user): void
    {
        $code = app(TelegramLinks::class)->issue($user);
        $this->linkCode = $code->code;
        $this->linkUrl = (string) TelegramLinks::deepLink($code->code);
        $this->linkExpired = false;
        $this->justLinked = false;
        $this->resetErrorBag('telegram');
    }

    /** wire:poll while a code is open: the modal turns to Connected as soon as the person pressed Start (or says the code ran out). */
    public function checkTelegram(): void
    {
        if ($this->linkCode === null || ! $this->userId) {
            return;
        }

        $chat = (string) User::query()->whereKey($this->userId)->value('telegram_chat_id');
        $row = TelegramLinkCode::query()->where('code', $this->linkCode)->first();
        if ($chat !== '' && ($chat !== $this->telegramChatId || ($row !== null && $row->used_at !== null))) {
            $this->telegramChatId = $chat;
            $this->linkCode = null;
            $this->justLinked = true;
        } elseif ($row === null || ! $row->isUsable()) {
            $this->linkCode = null;
            $this->linkExpired = true;
        }
    }

    /** Keeps the form from overwriting a chat id that was linked meanwhile. */
    private function syncTelegram(): void
    {
        if ($this->userId && $this->linkCode !== null) {
            $chat = (string) User::query()->whereKey($this->userId)->value('telegram_chat_id');
            if ($chat !== '') {
                $this->telegramChatId = $chat;
            }
        }
    }

    public function disconnectTelegram(): void
    {
        if ($this->userId) {
            User::query()->whereKey($this->userId)->update(['telegram_chat_id' => null]);
        }
        $this->telegramChatId = '';
        $this->linkCode = null;
        $this->justLinked = false;
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
            'telegramChatId' => __('Telegram chat id'),
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['userId', 'name', 'email', 'password', 'role', 'shopIds', 'canSeeProfit', 'telegramChatId', 'linkCode', 'linkUrl', 'linkExpired', 'justLinked']);
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
            'editing' => $this->userId ? User::query()->find($this->userId, ['id', 'is_active', 'telegram_chat_id']) : null,
            'botConfigured' => app(Telegram::class)->configured(),
            'shops' => Shop::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
