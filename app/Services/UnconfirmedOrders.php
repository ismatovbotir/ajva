<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLink;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "not confirmed orders" report: what is stuck between the Ordering report and the goods on the shelf.
 *
 *  draft     a draft nobody confirmed. Age from creation. Amber after 2 hours, red after 24 hours (one working day,
 *            counted as 24 h).
 *  receipt   confirmed, the shop has not confirmed the receipt. Age from confirmation. Red when the delivery day is
 *            over (OrderReceiving::deadline) or the order was flagged expired.
 *  approval  sent by the shop with exceptions, waiting for an admin (pending_approval + review_status pending).
 *            Age from the submission. Amber after 2 hours, red after 24 hours.
 *  sync      confirmed, 1C sync failed (red) or still queued for more than 15 minutes (amber). An order can be in
 *            `receipt` and `sync` at once (kinds).
 *
 * Scope: the shops the viewer may see (ShopAccess; admin = all), optionally one shop. Oldest first.
 */
class UnconfirmedOrders
{
    public const DRAFT_AMBER_MINUTES = 120;

    public const DRAFT_RED_MINUTES = 1440;

    public const QUEUED_STALE_MINUTES = 15;

    /** Amber after 2 hours waiting for an admin, red after 24 hours (the same thresholds as a draft). */
    public const APPROVAL_AMBER_MINUTES = 120;

    public const APPROVAL_RED_MINUTES = 1440;

    public const TYPES = ['all', 'draft', 'receipt', 'sync', 'approval'];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $shopId = null, string $type = 'all', ?Carbon $now = null): Collection
    {
        $now ??= now();

        $query = ShopAccess::restrict(Order::query(), 'orders.shop_id')
            ->with('shop:id,name')
            ->withCount('items')
            ->where(function ($q) {
                $q->where('status', OrderStatus::Draft->value)
                    ->orWhere(fn ($q2) => $q2->where('status', OrderStatus::PendingApproval->value)->where('review_status', Order::REVIEW_PENDING))
                    ->orWhere(fn ($q2) => $q2->where('status', OrderStatus::Confirmed->value)->whereNull('received_at'));
            });
        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }
        $orders = $query->get();
        if ($orders->isEmpty()) {
            return collect();
        }

        $people = OrderTelegram::responsibleByShop($orders->pluck('shop_id')->unique()->values()->all());
        $opened = OrderLink::query()->whereIn('order_id', $orders->pluck('id'))->whereNotNull('last_used_at')
            ->get(['order_id', 'last_used_at'])->groupBy('order_id')->map(fn ($g) => $g->max('last_used_at'));

        // When the waiting orders were sent for approval (latest submission).
        $submitted = OrderEvent::query()->whereIn('order_id', $orders->filter->isAwaitingApproval()->pluck('id'))
            ->where('action', OrderEvent::SENT_FOR_APPROVAL)
            ->get(['order_id', 'created_at'])->groupBy('order_id')->map(fn ($g) => $g->max('created_at'));

        $rows = $orders->map(function (Order $order) use ($now, $people, $opened, $submitted) {
            $isDraft = $order->status === OrderStatus::Draft;
            $isApproval = $order->isAwaitingApproval();
            $since = $isDraft ? $order->created_at : ($isApproval ? ($submitted->get($order->id) ?? $order->updated_at ?? $order->created_at) : ($order->confirmed_at ?? $order->created_at));
            $age = max(0, (int) $since->diffInMinutes($now));

            $kinds = [];
            $severity = 'none';
            if ($isApproval) {
                $kinds[] = 'approval';
                $severity = $age >= self::APPROVAL_RED_MINUTES ? 'red' : ($age >= self::APPROVAL_AMBER_MINUTES ? 'amber' : 'none');
            } elseif ($isDraft) {
                $kinds[] = 'draft';
                $severity = $age >= self::DRAFT_RED_MINUTES ? 'red' : ($age >= self::DRAFT_AMBER_MINUTES ? 'amber' : 'none');
            } else {
                $kinds[] = 'receipt';
                $overdue = OrderReceiving::isOverdue($order, $now);
                if ($overdue || $order->close_reason === Order::CLOSE_EXPIRED) {
                    $severity = 'red';
                }
                if ($order->sync_status === OrderSyncStatus::Failed) {
                    $kinds[] = 'sync';
                    $severity = 'red';
                } elseif ($order->sync_status === OrderSyncStatus::Queued && ($order->queued_at ?? $since)->diffInMinutes($now) >= self::QUEUED_STALE_MINUTES) {
                    $kinds[] = 'sync';
                    $severity = $severity === 'red' ? 'red' : 'amber';
                }
            }

            return [
                'order' => $order,
                'kinds' => $kinds,
                'age_minutes' => $age,
                'since' => $since,
                'severity' => $severity,
                'overdue' => ! $isDraft && ($overdue ?? false),
                'expired' => ! $isDraft && $order->close_reason === Order::CLOSE_EXPIRED,
                'people' => $people[$order->shop_id] ?? collect(),
                'opened_at' => $opened->get($order->id),
                'telegram_status' => $isDraft ? $order->telegram_status : null,
            ];
        });

        if ($type !== 'all') {
            $rows = $rows->filter(fn ($r) => in_array($type, $r['kinds'], true));
        }

        return $rows->sortByDesc('age_minutes')->values();
    }

    /** "3 h 20 min" / "2 d 4 h". */
    public static function ageLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return __(':m min', ['m' => $minutes]);
        }
        if ($minutes < 1440) {
            return __(':h h :m min', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60]);
        }

        return __(':d d :h h', ['d' => intdiv($minutes, 1440), 'h' => intdiv($minutes % 1440, 60)]);
    }
}
