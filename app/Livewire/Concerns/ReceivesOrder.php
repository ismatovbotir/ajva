<?php

namespace App\Livewire\Concerns;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Form state of "Confirm receipt" shared by the signed-in order page and the phone page: a received quantity per
 * line (prefilled with the ordered one) and an optional reason. Values come from the browser and are validated again
 * in App\Services\OrderReceiving.
 */
trait ReceivesOrder
{
    /** @var array<int, string> item id => received quantity */
    public array $recvQty = [];

    /** @var array<int, string> item id => reason (damaged | missing | extra | other | '') */
    public array $recvReason = [];

    /** Fills the form with the ordered quantities the first time a confirmed order is shown. */
    protected function ensureReceiveForm(Order $order, Collection $lines): void
    {
        if (! $order->isAwaitingReceipt() || $this->recvQty !== []) {
            return;
        }
        foreach ($lines as $line) {
            $this->recvQty[$line->item_id] = rtrim(rtrim(number_format((float) $line->qty, 3, '.', ''), '0'), '.');
            $this->recvReason[$line->item_id] = '';
        }
    }
}
