<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Jobs\SyncOrderTo1C;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Order state changes that start the 1C sync. Status and sync state are separate:
 * confirming moves pending_approval -> confirmed and queues the push (sync_status = queued).
 * (`approved` is the older name of the same admin approval and stays valid for existing rows;
 * it is confirmed through the same action.)
 */
class OrderSync
{
    public function confirm(Order $order, ?User $by = null, string $channel = OrderEvent::CHANNEL_PANEL): void
    {
        abort_unless(in_array($order->status, [OrderStatus::Draft, OrderStatus::PendingApproval, OrderStatus::Approved], true), 422);
        abort_if($order->items()->count() === 0, 422);

        $order->forceFill([
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by' => $by?->id,
            'sync_status' => OrderSyncStatus::Queued,
            'queued_at' => now(),
            'sync_attempts' => 0,
            'sync_error' => null,
        ])->save();
        OrderEvent::record($order, $by?->id, $channel, OrderEvent::CONFIRMED);

        $this->dispatch($order);
    }

    /** Re-queue a failed (or still queued) sync of a confirmed order; a synced order is never re-sent. */
    public function retry(Order $order): void
    {
        abort_unless($order->status === OrderStatus::Confirmed, 422);
        abort_if($order->sync_status === OrderSyncStatus::Synced, 422);

        $order->forceFill([
            'sync_status' => OrderSyncStatus::Queued,
            'queued_at' => now(),
            'sync_attempts' => 0,
            'sync_error' => null,
        ])->save();

        $this->dispatch($order);
    }

    /** Push the order to 1C through the queue (a failure is stored on the order, never thrown). */
    public function dispatch(Order $order): void
    {
        try {
            SyncOrderTo1C::dispatch($order->id);
        } catch (Throwable $e) {
            // The sync queue runs the job inline and rethrows; the failure is already stored on the order.
            Log::warning('Order sync to 1C failed', ['order' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
