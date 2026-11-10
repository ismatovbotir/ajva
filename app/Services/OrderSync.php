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
    /**
     * The confirm button of a DRAFT. Exceptions (OrderExceptions) decide: none = confirmed and queued for 1C as
     * always; with exceptions an admin must decide ($anyway = he saw the list and confirms: recorded as
     * exception_approved), an operator's order goes to the admins (pending_approval + review_status pending, lines
     * locked, NOT in transit, NOT sent to 1C).
     *
     * @return array{outcome: string, exceptions: array<int, array<string, mixed>>} outcome: confirmed | pending | needs_decision
     */
    public function submit(Order $order, ?User $by, string $channel = OrderEvent::CHANNEL_PANEL, bool $anyway = false): array
    {
        abort_unless($order->status === OrderStatus::Draft, 422);
        abort_if($order->items()->count() === 0, 422);

        $exceptions = app(OrderExceptions::class)->evaluate($order);
        if ($exceptions === []) {
            $this->confirm($order, $by, $channel);

            return ['outcome' => 'confirmed', 'exceptions' => []];
        }

        if ($by?->isAdmin() && $channel === OrderEvent::CHANNEL_PANEL) {
            if (! $anyway) {
                return ['outcome' => 'needs_decision', 'exceptions' => $exceptions];
            }
            $this->confirm($order, $by, $channel);
            $order->forceFill([
                'review_status' => Order::REVIEW_APPROVED,
                'review_exceptions' => $exceptions,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ])->save();
            OrderEvent::record($order, $by->id, $channel, OrderEvent::EXCEPTION_APPROVED, null, null, null, OrderExceptions::summary($exceptions));

            return ['outcome' => 'confirmed', 'exceptions' => $exceptions];
        }

        $order->forceFill([
            'status' => OrderStatus::PendingApproval,
            'review_status' => Order::REVIEW_PENDING,
            'review_note' => null,
            'review_exceptions' => $exceptions,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();
        OrderEvent::record($order, $by?->id, $channel, OrderEvent::SENT_FOR_APPROVAL, null, null, null, OrderExceptions::summary($exceptions));
        $this->notify(fn (OrderReminders $r) => $r->approvalRequested($order));

        return ['outcome' => 'pending', 'exceptions' => $exceptions];
    }

    /** Admin approves an order waiting for approval: confirmed and queued for 1C. */
    public function approve(Order $order, User $admin): void
    {
        abort_unless($order->isAwaitingApproval(), 422);

        $this->confirm($order, $admin);
        $order->forceFill(['review_status' => Order::REVIEW_APPROVED, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
        OrderEvent::record($order, $admin->id, OrderEvent::CHANNEL_PANEL, OrderEvent::APPROVED);
        $this->notify(fn (OrderReminders $r) => $r->approvalDecided($order, true));
    }

    /** Admin sends it back to the shop: a draft again, with the reason (required). */
    public function reject(Order $order, User $admin, string $reason): void
    {
        abort_unless($order->isAwaitingApproval(), 422);
        $reason = trim($reason);
        abort_if($reason === '', 422);

        $order->forceFill([
            'status' => OrderStatus::Draft,
            'review_status' => Order::REVIEW_REJECTED,
            'review_note' => mb_substr($reason, 0, 1000),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ])->save();
        OrderEvent::record($order, $admin->id, OrderEvent::CHANNEL_PANEL, OrderEvent::REJECTED, null, null, null, mb_substr($reason, 0, 255));
        $this->notify(fn (OrderReminders $r) => $r->approvalDecided($order, false));
    }

    /** Telegram notices never fail the order. */
    private function notify(callable $send): void
    {
        try {
            $send(app(OrderReminders::class));
        } catch (Throwable $e) {
            Log::warning('Order approval notice failed', ['error' => $e->getMessage()]);
        }
    }

    /** Raw confirmation: locks the order and queues the 1C push. Use submit() for a draft (it checks the exceptions). */
    public function confirm(Order $order, ?User $by = null, string $channel = OrderEvent::CHANNEL_PANEL): void
    {
        abort_unless(in_array($order->status, [OrderStatus::Draft, OrderStatus::PendingApproval, OrderStatus::Approved], true), 422);
        abort_if($order->items()->count() === 0, 422);

        $order->forceFill(array_merge([
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by' => $by?->id,
            'sync_status' => OrderSyncStatus::Queued,
            'queued_at' => now(),
            'sync_attempts' => 0,
            'sync_error' => null,
        ], $order->review_status === Order::REVIEW_REJECTED ? ['review_status' => null, 'review_note' => null, 'review_exceptions' => null] : []))->save();
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
