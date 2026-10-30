<?php

namespace App\Livewire\Telegram;

use App\Models\TelegramMessage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Telegram messages'])]
class Messages extends Component
{
    use WithPagination;

    public string $search = '';

    /** '' = both, 'in' or 'out'. */
    public string $direction = '';

    /** Runs on every request, including Livewire updates (they skip route middleware). */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingDirection(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $messages = TelegramMessage::query()
            ->with(['telegram:id,tg_id,username,first_name,last_name', 'sender:id,name'])
            ->when(in_array($this->direction, ['in', 'out'], true), fn ($q) => $q->where('direction', $this->direction))
            ->when($this->search !== '', function ($query) {
                $term = "%{$this->search}%";
                $query->where(function ($q) use ($term) {
                    $q->where('text', 'like', $term)
                        ->orWhere('chat_id', 'like', $term)
                        ->orWhere('from_id', 'like', $term)
                        ->orWhereHas('telegram', fn ($u) => $u->where('username', 'like', $term)
                            ->orWhere('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term));
                });
            })
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(50);

        return view('livewire.telegram.messages', ['messages' => $messages]);
    }
}
