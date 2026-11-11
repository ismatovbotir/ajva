<?php

namespace App\Livewire\Orders;

use App\Models\Order;
use App\Services\OrderReminders;
use App\Services\UnconfirmedOrders;
use App\Support\ShopAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Not confirmed orders (live operational list, no Generate button): drafts nobody confirmed, confirmed orders the shop
 * has not received, orders whose 1C sync is stuck. Admin sees every shop, an operator only his shops (ShopAccess).
 * Figures: App\Services\UnconfirmedOrders. The admin can send a reminder to the shop's responsible people right away.
 */
#[Layout('components.layouts.app', ['title' => 'Not confirmed orders'])]
class Unconfirmed extends Component
{
    #[Url]
    public string $shop = '';

    #[Url]
    public string $type = 'all';

    /** Result line of the last "Send reminder now". */
    public string $notice = '';

    public function sendReminder(int $orderId): void
    {
        Gate::authorize('generate', Order::class);
        $order = Order::query()->findOrFail($orderId);

        $res = app(OrderReminders::class)->sendNow($order);
        $this->notice = match (true) {
            $res['recipients'] === 0 => __('Nobody to remind: no responsible user with Telegram (or the bot token is not set).'),
            $res['failed'] > 0 => __('Reminder sent to :sent, failed for :failed.', ['sent' => $res['sent'], 'failed' => $res['failed']]),
            default => __('Reminder sent to :count people.', ['count' => $res['sent']]),
        };
    }

    public function render()
    {
        $type = in_array($this->type, UnconfirmedOrders::TYPES, true) ? $this->type : 'all';
        $ids = ShopAccess::ids();
        // A shop outside the viewer's scope is ignored, never an error or a leak.
        $shopId = ctype_digit($this->shop) && ShopAccess::allows((int) $this->shop) ? (int) $this->shop : null;

        $rows = ShopAccess::hasNone() ? collect() : app(UnconfirmedOrders::class)->rows($shopId, $type);

        return view('livewire.orders.unconfirmed', [
            'rows' => $rows,
            'shops' => ShopAccess::restrictTo(DB::table('shops')->where('is_disabled', false), 'shops.id', $ids)->orderBy('name')->get(['id', 'name']),
            'noShops' => ShopAccess::hasNone(),
            'isAdmin' => Gate::allows('generate', Order::class),
            'types' => UnconfirmedOrders::TYPES,
        ]);
    }
}
