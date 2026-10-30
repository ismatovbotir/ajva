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
            'users' => $users,
            'panelUsers' => $panelUsers,
            'target' => $this->telegramId ? TelegramAccount::query()->find($this->telegramId) : null,
        ]);
    }
}
