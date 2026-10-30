<?php

namespace App\Livewire\Telegram;

use App\Models\TelegramMessage;
use App\Models\Telegram as TelegramAccount;
use App\Models\User;
use App\Services\Telegram;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Telegram users'])]
class Users extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showModal = false;

    public ?int $telegramId = null;

    public string $text = '';

    public ?string $result = null;

    public bool $failed = false;

    /** Runs on every request, including Livewire updates (they skip route middleware). */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function compose(int $id): void
    {
        TelegramAccount::query()->findOrFail($id);

        $this->resetErrorBag();
        $this->telegramId = $id;
        $this->text = '';
        $this->result = null;
        $this->failed = false;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->telegramId = null;
    }

    /** The assign form: which Telegram person, and the CRM user chosen for them. */
    public bool $showAssign = false;

    public ?int $assignTelegramId = null;

    public string $assignUserId = '';

    public function openAssign(int $id): void
    {
        $account = TelegramAccount::query()->findOrFail($id);

        $this->resetErrorBag();
        $this->assignTelegramId = $account->id;
        $this->assignUserId = (string) (User::query()->where('telegram_chat_id', (string) $account->tg_id)->value('id') ?? '');
        $this->showAssign = true;
    }

    public function closeAssign(): void
    {
        $this->showAssign = false;
        $this->assignTelegramId = null;
        $this->assignUserId = '';
    }

    /**
     * Links the Telegram person to a CRM user: the user's telegram_chat_id becomes the person's Telegram id (that
     * is where order messages go). A person belongs to one user, and a user has one Telegram.
     */
    public function assign(): void
    {
        $data = $this->validate(['assignUserId' => ['required', 'integer', 'exists:users,id']]);
        $account = TelegramAccount::query()->findOrFail($this->assignTelegramId);
        $user = User::query()->findOrFail((int) $data['assignUserId']);
        $chat = (string) $account->tg_id;

        if (! $user->is_active) {
            $this->addError('assignUserId', __('Activate the user first.'));

            return;
        }

        $holder = User::query()->where('telegram_chat_id', $chat)->whereKeyNot($user->id)->first();
        if ($holder !== null) {
            $this->addError('assignUserId', __('This Telegram is already assigned to :name. Unassign it first.', ['name' => $holder->name]));

            return;
        }

        $user->forceFill(['telegram_chat_id' => $chat])->save();
        $this->closeAssign();
    }

    /** Removes the link: the CRM user no longer gets Telegram messages. */
    public function unassign(int $id): void
    {
        $account = TelegramAccount::query()->findOrFail($id);
        User::query()->where('telegram_chat_id', (string) $account->tg_id)->update(['telegram_chat_id' => null]);
    }

    public function send(Telegram $telegram): void
    {
        $data = $this->validate(['text' => ['required', 'string', 'max:4000']]);
        $target = TelegramAccount::query()->findOrFail($this->telegramId);

        if (! $telegram->configured()) {
            $this->failed = true;
            $this->result = __('The Telegram bot token (TELEGRAM_BOT_TOKEN) is not set.');

            return;
        }

        $response = $telegram->send((string) $target->tg_id, $data['text']);
        if (! $response['ok']) {
            $this->failed = true;
            $this->result = (string) $response['error'];

            return;
        }

        TelegramMessage::query()->create([
            'telegram_id' => $target->id,
            'direction' => 'out',
            'chat_id' => $target->tg_id,
            'chat_type' => 'private',
            'type' => 'text',
            'text' => $data['text'],
            'sent_by' => auth()->id(),
            'sent_at' => now(),
        ]);
        $target->forceFill(['last_message_at' => now()])->save();

        $this->failed = false;
        $this->result = __('Message sent.');
        $this->text = '';
    }

    public function render()
    {
        $users = TelegramAccount::query()
            ->withCount('messages')
            ->when($this->search !== '', function ($query) {
                $term = "%{$this->search}%";
                $query->where(function ($q) use ($term) {
                    $q->where('username', 'like', $term)
                        ->orWhere('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('tg_id', 'like', $term);
                });
            })
            ->orderByDesc('last_message_at')
            ->paginate(20);

        // Panel users whose chat id is this Telegram person (private chat id = telegram id).
        $panelUsers = User::query()
            ->whereIn('telegram_chat_id', $users->getCollection()->pluck('tg_id')->map(fn ($id) => (string) $id)->all())
            ->pluck('name', 'telegram_chat_id');

        return view('livewire.telegram.users', [
            'assignable' => $this->showAssign
                ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role', 'telegram_chat_id'])
                : collect(),
            'assignTarget' => $this->assignTelegramId ? TelegramAccount::query()->find($this->assignTelegramId) : null,
            'users' => $users,
            'panelUsers' => $panelUsers,
            'target' => $this->telegramId ? TelegramAccount::query()->find($this->telegramId) : null,
        ]);
    }
}
