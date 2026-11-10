<?php

namespace App\Livewire\Orders;

use App\Enums\OrderStatus;
use App\Livewire\Concerns\EditsDraftOrder;
use App\Livewire\Concerns\ReceivesOrder;
use App\Livewire\Ordering\Index as OrderingIndex;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLink;
use App\Services\OrderReceiving;
use App\Services\OrderSync;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The personal secret link of an order (GET /o/{token}, no login): the token belongs to ONE user (order_links).
 * While the order is a DRAFT he edits quantities, removes lines, adds items and confirms - the same draft-only actions
 * as Orders\Show (shared trait), every one recorded for that user (order_events, channel link). Once the order is
 * confirmed and not yet received the same link shows ONLY the "Confirm receipt" form (received quantity per line,
 * optional reason); lines cannot be edited any more. The token is the only credential and works only for those two
 * states and only while the user is still active and assigned to the shop: anything else (unknown token, received /
 * cancelled / deleted order, deactivated or unassigned user) is a plain 404, checked in mount(), hydrate() (every
 * Livewire request) and again in every action. No cost, profit or cashier data is ever read or shown on this page.
 */
#[Layout('components.layouts.public-order', ['title' => 'Order'])]
class PublicDraft extends Component
{
    use EditsDraftOrder;
    use ReceivesOrder;

    #[Locked]
    public string $token = '';

    /** Set by confirm(): only a thank-you note is rendered. */
    #[Locked]
    public bool $done = false;

    /** Set by confirm() when the order went to the admins because of exceptions (shown with the reasons). */
    #[Locked]
    public bool $sentForApproval = false;

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $exceptions = [];

    /** Set by receive(): only a thank-you note is rendered. */
    #[Locked]
    public bool $received = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->comment = (string) $this->order()->comment;
    }

    public function hydrate(): void
    {
        if (! $this->done && ! $this->received) {
            $this->order();
        }
    }

    /** The link behind the token (order + user loaded), or a 404. */
    private function link(): OrderLink
    {
        $link = OrderLink::resolve($this->token, true);
        abort_if($link === null, 404);

        return $link;
    }

    /** The order behind the token (a draft or awaiting receipt), or a 404 (unknown token, closed, deleted, user not allowed any more). */
    private function order(): Order
    {
        return $this->link()->order;
    }

    /** Editing is for drafts only: a confirmed order behind the same link refuses every edit. */
    protected function draftOrder(): Order
    {
        $order = $this->order();
        abort_unless($order->status === OrderStatus::Draft, 404);

        return $order;
    }

    protected function actorId(): ?int
    {
        return $this->link()->user_id;
    }

    protected function channel(): string
    {
        return OrderEvent::CHANNEL_LINK;
    }

    public function confirm(): void
    {
        $link = $this->link();
        $order = $link->order;
        abort_unless($order->status === OrderStatus::Draft, 404);

        if ($order->items()->count() === 0) {
            $this->addError('confirm', __('The order is empty. Add at least one item.'));

            return;
        }

        // What is typed in the comment box counts even when the field was never blurred.
        $this->persistComment($order);
        if ($this->getErrorBag()->has('comment')) {
            return;
        }

        // With exceptions the order goes to the admins instead of 1C (lines locked, pending approval).
        $result = app(OrderSync::class)->submit($order, $link->user, OrderEvent::CHANNEL_LINK);
        OrderingIndex::bumpOrdersVersion();
        $this->done = true;
        $this->sentForApproval = $result['outcome'] === 'pending';
        $this->exceptions = $result['exceptions'];
    }

    /** Confirm receipt: only a confirmed, not yet received order, only by the link's own (active, assigned) user. */
    public function receive(): void
    {
        $link = $this->link();
        abort_unless($link->order->isAwaitingReceipt(), 404);

        app(OrderReceiving::class)->receive($link->order, $link->user, OrderEvent::CHANNEL_LINK, $this->recvQty, $this->recvReason);
        $this->received = true;
    }

    public function render()
    {
        if ($this->done || $this->received) {
            return view('livewire.orders.public-draft', ['done' => $this->done, 'received' => $this->received, 'order' => null, 'items' => collect(), 'results' => collect(), 'meId' => null, 'userName' => '', 'events' => collect(), 'sentForApproval' => $this->sentForApproval, 'reasons' => $this->exceptions]);
        }

        $link = $this->link();
        $order = $link->order->load('shop');
        $items = $this->sortLines($order->items()->with('item')->get());
        $results = $order->status === OrderStatus::Draft ? $this->searchItems($items->pluck('item_id')) : collect();
        $this->ensureReceiveForm($order, $items);

        return view('livewire.orders.public-draft', ['done' => false, 'received' => false, 'order' => $order, 'items' => $items, 'results' => $results, 'meId' => $link->user_id, 'userName' => $link->user->name, 'events' => $this->events($order), 'sentForApproval' => false, 'reasons' => (array) $order->review_exceptions]);
    }

    private function events(Order $order)
    {
        return $order->events()->with(['user:id,name', 'item:id,name'])->latest('id')->limit(50)->get();
    }
}
