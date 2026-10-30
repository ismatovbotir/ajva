<?php

namespace App\Livewire\Orders;

use App\Enums\OrderStatus;
use App\Livewire\Concerns\EditsDraftOrder;
use App\Livewire\Ordering\Index as OrderingIndex;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderLink;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\OrderSync;
use App\Services\OrderTelegram;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One order. While it is a DRAFT the shop's responsible person (or an admin) edits quantities, removes lines
 * and adds any item (manual lines: added_manually, no suggestion); an admin may delete it. Confirm locks it
 * and queues the 1C sync. Every action re-authorizes against the policy, so a confirmed order refuses edits (403).
 */
#[Layout('components.layouts.app', ['title' => 'Order'])]
class Show extends Component
{
    use EditsDraftOrder;

    #[Locked]
    public int $orderId = 0;

    public string $telegramStatus = '';

    public string $telegramNote = '';

    /** The responsible user whose personal link the admin copies, and the link just made (admin only). */
    public string $linkUserId = '';

    public string $generatedLink = '';

    public function mount(Order $order): void
    {
        // A shop outside the user's scope does not exist for them.
        abort_unless(Gate::allows('view', $order), 404);

        $this->orderId = $order->id;
        $this->comment = (string) $order->comment;
    }

    private function order(): Order
    {
        return Order::query()->findOrFail($this->orderId);
    }

    protected function draftOrder(): Order
    {
        $order = $this->order();
        Gate::authorize('edit', $order);

        return $order;
    }

    protected function actorId(): ?int
    {
        return auth()->id();
    }

    protected function channel(): string
    {
        return OrderEvent::CHANNEL_PANEL;
    }

    /**
     * Admin only, drafts only: the personal link of the chosen responsible user (created on first use). Copying it
     * does not make the admin the actor: whatever is done through it is recorded for that user.
     */
    public function makeLink(): void
    {
        Gate::authorize('generate', Order::class);
        $order = $this->order();
        abort_unless($order->status === OrderStatus::Draft, 404);

        $user = OrderTelegram::responsible($order->shop_id)->firstWhere('id', (int) $this->linkUserId);
        if ($user === null) {
            $this->generatedLink = '';
            $this->addError('linkUserId', __('Choose a responsible user.'));

            return;
        }
        $this->resetErrorBag('linkUserId');
        $this->generatedLink = OrderLink::forUser($order, $user)->url();
        $this->dispatch('order-link-ready', url: $this->generatedLink);
    }

    /** Admin only, drafts only: send the secret link to the shop's responsible users in Telegram again. */
    public function sendTelegram(): void
    {
        Gate::authorize('generate', Order::class);
        $order = $this->order();
        abort_unless($order->status === OrderStatus::Draft, 404);

        // Same code path as the console command; the outcome is read back from the order.
        Artisan::call('orders:send-telegram', ['order' => $order->id]);
        $order = $order->fresh();
        $this->generatedLink = '';
        $this->telegramStatus = (string) $order->telegram_status;
        $this->telegramNote = (string) $order->telegram_note;
    }

    public function confirm(): void
    {
        $order = $this->order();
        Gate::authorize('confirm', $order);

        if (Gate::allows('edit', $order)) {
            $this->persistComment($order);
            if ($this->getErrorBag()->has('comment')) {
                return;
            }
        }

        app(OrderSync::class)->confirm($order, auth()->user());
        OrderingIndex::bumpOrdersVersion();
    }

    public function retrySync(): void
    {
        $order = $this->order();
        Gate::authorize('retrySync', $order);

        app(OrderSync::class)->retry($order);
    }

    public function delete()
    {
        $order = $this->order();
        Gate::authorize('delete', $order);

        $order->delete();

        return $this->redirectRoute('orders.index');
    }

    public function render()
    {
        $order = $this->order()->load('shop');
        $items = $order->items()->with('item')->get()->sortBy(fn (OrderItem $i) => [$i->added_manually ? 1 : 0, $i->item?->name])->values();

        $results = collect();
        if ($order->status === OrderStatus::Draft && mb_strlen(trim($this->search)) >= 2) {
            $results = Item::query()
                ->where('name', 'like', '%'.trim($this->search).'%')
                ->whereNotIn('id', $items->pluck('item_id'))
                ->orderBy('name')->limit(8)->get(['id', 'name']);
        }

        // Personal links shown on the page: the admin sees every responsible person's link, an assigned operator his own
        // (created on first view; what is done through a link is recorded for its owner).
        $links = [];
        if ($order->status === OrderStatus::Draft) {
            if (Gate::allows('generate', Order::class)) {
                foreach (OrderTelegram::responsible($order->shop_id) as $person) {
                    $links[] = ['name' => $person->name, 'url' => OrderLink::forUser($order, $person)->url()];
                }
            } elseif (auth()->user() !== null && OrderLink::mayAct(auth()->user(), (int) $order->shop_id)) {
                $links[] = ['name' => auth()->user()->name, 'url' => OrderLink::forUser($order, auth()->user())->url()];
            }
        }

        // Telegram delivery per responsible person (admin only): the latest attempt, and whether the personal link was opened.
        $deliveries = collect();
        if (Gate::allows('generate', Order::class)) {
            $latest = TelegramMessage::query()->where('order_id', $order->id)->whereNotNull('user_id')
                ->orderBy('id')->get(['user_id', 'status', 'error', 'sent_at'])->keyBy('user_id');
            $opened = OrderLink::query()->where('order_id', $order->id)->pluck('last_used_at', 'user_id');
            $people = User::query()->whereIn('id', $latest->keys()->merge($opened->keys())->unique())->orderBy('name')->get(['id', 'name']);
            $deliveries = $people->map(fn (User $u) => [
                'name' => $u->name,
                'status' => $latest->get($u->id)?->status,
                'error' => $latest->get($u->id)?->error,
                'sent_at' => $latest->get($u->id)?->sent_at,
                'opened_at' => $opened->get($u->id),
            ])->filter(fn ($d) => $d['status'] !== null)->values();
        }

        return view('livewire.orders.show', [
            'deliveries' => $deliveries,
            'links' => $links,
            'order' => $order,
            'items' => $items,
            'results' => $results,
            'canEdit' => Gate::allows('edit', $order),
            'canConfirm' => Gate::allows('confirm', $order),
            'canDelete' => Gate::allows('delete', $order),
            'canShare' => $order->status === OrderStatus::Draft && Gate::allows('generate', Order::class),
            'responsible' => $order->status === OrderStatus::Draft && Gate::allows('generate', Order::class) ? OrderTelegram::responsible($order->shop_id) : collect(),
            'events' => $order->events()->with(['user:id,name', 'item:id,name'])->latest('id')->limit(100)->get(),
            'canRetry' => Gate::allows('retrySync', $order) && $order->status === OrderStatus::Confirmed && in_array($order->sync_status->value, ['failed', 'queued'], true),
            'onecConfigured' => (string) config('services.onec.orders_url') !== '',
        ]);
    }
}
