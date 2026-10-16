<?php

namespace App\Livewire\Orders;

use App\Livewire\Concerns\EditsDraftOrder;
use App\Livewire\Ordering\Index as OrderingIndex;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\OrderLink;
use App\Services\OrderSync;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The personal secret link of a draft order (GET /o/{token}, no login): the token belongs to ONE user (order_links),
 * who edits quantities, removes lines, adds items and confirms - the same draft-only actions as Orders\Show (shared
 * trait), every one recorded for that user (order_events, channel link). The token is the only credential and works
 * ONLY while the order is a draft and the user is still active and assigned to the shop: anything else (unknown
 * token, confirmed / deleted order, deactivated or unassigned user) is a plain 404, checked in mount(), hydrate()
 * (every Livewire request) and again in every action. Confirming records confirmed_by = the link's user, queues the
 * 1C sync as usual and kills the link at once. No cost, profit or cashier data is ever read or shown on this page.
 */
#[Layout('components.layouts.public-order', ['title' => 'Order'])]
class PublicDraft extends Component
{
    use EditsDraftOrder;

    #[Locked]
    public string $token = '';

    /** Set by confirm(): the order is gone from the link, only a thank-you note is rendered. */
    #[Locked]
    public bool $done = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->comment = (string) $this->order()->comment;
    }

    public function hydrate(): void
    {
        if (! $this->done) {
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

    /** The draft behind the token, or a 404 (unknown token, not a draft any more, deleted, user not allowed any more). */
    private function order(): Order
    {
        return $this->link()->order;
    }

    protected function draftOrder(): Order
    {
        return $this->order();
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

        if ($order->items()->count() === 0) {
            $this->addError('confirm', __('The order is empty. Add at least one item.'));

            return;
        }

        // What is typed in the comment box counts even when the field was never blurred.
        $this->persistComment($order);
        if ($this->getErrorBag()->has('comment')) {
            return;
        }

        app(OrderSync::class)->confirm($order, $link->user, OrderEvent::CHANNEL_LINK);
        OrderingIndex::bumpOrdersVersion();
        $this->done = true;
    }

    public function render()
    {
        if ($this->done) {
            return view('livewire.orders.public-draft', ['done' => true, 'order' => null, 'items' => collect(), 'results' => collect(), 'meId' => null, 'userName' => '', 'events' => collect()]);
        }

        $link = $this->link();
        $order = $link->order->load('shop');
        $items = $order->items()->with('item')->get()
            ->sortBy(fn (OrderItem $i) => [$i->added_manually ? 1 : 0, $i->item?->name])->values();

        $results = collect();
        if (mb_strlen(trim($this->search)) >= 2) {
            $results = Item::query()
                ->where('name', 'like', '%'.trim($this->search).'%')
                ->whereNotIn('id', $items->pluck('item_id'))
                ->orderBy('name')->limit(8)->get(['id', 'name']);
        }

        return view('livewire.orders.public-draft', ['done' => false, 'order' => $order, 'items' => $items, 'results' => $results, 'meId' => $link->user_id, 'userName' => $link->user->name, 'events' => $this->events($order)]);
    }

    private function events(Order $order)
    {
        return $order->events()->with(['user:id,name', 'item:id,name'])->latest('id')->limit(50)->get();
    }
}
