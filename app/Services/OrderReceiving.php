<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Livewire\Ordering\Index as OrderingIndex;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes the loop of a confirmed order: the shop confirms the goods arrived (per line received quantity, optional
 * reason when it differs). The order becomes `fulfilled` (received_at, received_by, close_reason `received`), so it
 * stops counting as in transit at once. Shared by the phone page (personal link) and the signed-in order page; the
 * CALLER authorizes (policy `receive` / a resolved personal link), this class only guards the state.
 */
class OrderReceiving
{
    public const REASONS = ['damaged', 'missing', 'extra', 'other'];

    /** @return array<string, string> reason => label */
    public static function reasonLabels(): array
    {
        return [
            'damaged' => __('Damaged'),
            'missing' => __('Missing'),
            'extra' => __('Extra'),
            'other' => __('Other'),
        ];
    }

    /**
     * Deadline of the delivery day: today's `inventory.delivery_deadline` for an order confirmed before it, the
     * next day's for one confirmed after it. Delivery is normally the same day.
     */
    public static function deadline(Order $order): Carbon
    {
        $at = ($order->confirmed_at ?? $order->created_at ?? now())->copy();
        [$h, $m] = array_pad(array_map('intval', explode(':', (string) config('inventory.delivery_deadline', '20:00'))), 2, 0);
        $deadline = $at->copy()->setTime($h, $m);

        return $at->gt($deadline) ? $deadline->addDay() : $deadline;
    }

    public static function isOverdue(Order $order, ?Carbon $now = null): bool
    {
        return $order->isAwaitingReceipt() && ($now ?? now())->gt(self::deadline($order));
    }

    /**
     * @param  array<int|string, mixed>  $qty  item id => received quantity (a missing line = received as ordered)
     * @param  array<int|string, mixed>  $reasons  item id => reason (only used when the quantity differs)
     */
    public function receive(Order $order, ?User $by, string $channel, array $qty, array $reasons = []): void
    {
        abort_unless($order->isAwaitingReceipt(), 422);

        $lines = $order->items()->get();
        $values = [];
        foreach ($lines as $line) {
            $raw = $qty[$line->item_id] ?? $line->qty;
            if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 1000000) {
                throw ValidationException::withMessages(['receive' => __('The received quantity must be a number, 0 or more.')]);
            }
            $values[$line->id] = round((float) $raw, 3);
        }

        DB::transaction(function () use ($order, $by, $channel, $lines, $values, $reasons) {
            $differs = 0;
            foreach ($lines as $line) {
                $received = $values[$line->id];
                $ordered = (float) $line->qty;
                $same = abs($received - $ordered) < 1e-9;
                $reason = $reasons[$line->item_id] ?? null;
                $reason = ! $same && in_array($reason, self::REASONS, true) ? $reason : null;

                $line->forceFill(['qty_received' => $received, 'discrepancy_reason' => $reason])->save();

                if (! $same) {
                    $differs++;
                    OrderEvent::record($order, $by?->id, $channel, OrderEvent::RECEIVED, $line->item_id, $ordered, $received, $reason);
                }
            }

            $order->forceFill([
                'status' => OrderStatus::Fulfilled,
                'received_at' => now(),
                'received_by' => $by?->id,
                'close_reason' => Order::CLOSE_RECEIVED,
            ])->save();

            // Order-level entry (no item): note = number of lines that differ.
            OrderEvent::record($order, $by?->id, $channel, OrderEvent::RECEIVED, null, null, null, (string) $differs);
        });

        OrderingIndex::bumpOrdersVersion();
    }
}
