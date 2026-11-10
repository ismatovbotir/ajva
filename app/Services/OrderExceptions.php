<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Exceptions of an order, evaluated when it is confirmed (App\Services\OrderSync::submit). An order with none goes to
 * 1C as usual; with some, a shop operator's order waits for an admin (an admin confirms anyway or sends it back).
 * Thresholds: config/inventory.php (order_exception_*), the whole check is switched off by inventory.order_exceptions.
 *
 *  line_over_max     line qty > N x the item's max rule of the shop (rule with max > 0 needed)
 *  line_over_system  system line qty > N x its suggested qty
 *  line_under_system system line qty < N x its suggested qty, only for ABC class A items of the shop (28-day revenue)
 *  manual_no_rule    a line added by hand for an item without an order rule in the shop (only when the shop has rules)
 *  total_high        total pieces > N x the shop's average confirmed order total of the last 4 weeks (>= 3 earlier orders)
 *  qty_insane        line qty above order_exception_max_qty
 *
 * Every rule needs its data and is skipped silently without it. Set-based: one query each for rules, ABC revenue
 * (only when a low line exists) and the order history.
 */
class OrderExceptions
{
    /**
     * @return array<int, array{code: string, severity: string, item_id: ?int, text: string}> severity: danger | warning
     */
    public function evaluate(Order $order): array
    {
        if (! config('inventory.order_exceptions', true)) {
            return [];
        }

        $lines = $order->items()->with('item:id,name')->get();
        if ($lines->isEmpty()) {
            return [];
        }

        $rules = DB::table('item_order_rules')->where('shop_id', $order->shop_id)->get(['item_id', 'max']);
        $max = $rules->filter(fn ($r) => (float) $r->max > 0)->mapWithKeys(fn ($r) => [(int) $r->item_id => (float) $r->max]);
        $shopHasRules = $max->isNotEmpty();

        $overMax = (float) config('inventory.order_exception_max_multiple', 3);
        $over = (float) config('inventory.order_exception_over_suggested', 2);
        $under = (float) config('inventory.order_exception_under_suggested', 0.3);
        $insane = (float) config('inventory.order_exception_max_qty', 100000);

        $classA = null;   // computed once, only if a line is below its suggestion
        $out = [];
        $total = 0.0;

        foreach ($lines as $line) {
            $qty = (float) $line->qty;
            $total += $qty;
            $id = (int) $line->item_id;
            $name = (string) ($line->item?->name ?? '#'.$id);

            if ($insane > 0 && $qty > $insane) {
                $out[] = $this->make('qty_insane', 'danger', $id, __(':item: quantity :qty is not a sane number.', ['item' => $name, 'qty' => self::fmt($qty)]));
            }
            if ($overMax > 0 && isset($max[$id]) && $qty > $overMax * $max[$id]) {
                $out[] = $this->make('line_over_max', 'danger', $id, __(':item: :qty is more than :n x the maximum of :max.', ['item' => $name, 'qty' => self::fmt($qty), 'n' => self::fmt($overMax), 'max' => self::fmt($max[$id])]));
            }

            $suggested = $line->suggested_qty === null ? null : (float) $line->suggested_qty;
            if (! $line->added_manually && $suggested !== null && $suggested > 0) {
                if ($over > 0 && $qty > $over * $suggested) {
                    $out[] = $this->make('line_over_system', 'warning', $id, __(':item: :qty is more than :n x the system suggestion of :sug.', ['item' => $name, 'qty' => self::fmt($qty), 'n' => self::fmt($over), 'sug' => self::fmt($suggested)]));
                } elseif ($under > 0 && $qty < $under * $suggested) {
                    $classA ??= $this->classA((int) $order->shop_id);
                    if (isset($classA[$id])) {
                        $out[] = $this->make('line_under_system', 'warning', $id, __(':item (class A): :qty is less than :n x the system suggestion of :sug.', ['item' => $name, 'qty' => self::fmt($qty), 'n' => self::fmt($under), 'sug' => self::fmt($suggested)]));
                    }
                }
            }

            if ($line->added_manually && $shopHasRules && ! isset($max[$id])) {
                $out[] = $this->make('manual_no_rule', 'warning', $id, __(':item: added by hand, but the shop has no order rule for it.', ['item' => $name]));
            }
        }

        $average = $this->averageTotal($order);
        $multiple = (float) config('inventory.order_exception_total_multiple', 1.5);
        if ($average !== null && $multiple > 0 && $total > $multiple * $average) {
            $out[] = $this->make('total_high', 'warning', null, __('The order has :total pieces, more than :n x the shop average of :avg.', ['total' => self::fmt($total), 'n' => self::fmt($multiple), 'avg' => self::fmt($average)]));
        }

        return $out;
    }

    /** One-line summary for the history note. */
    public static function summary(array $exceptions): string
    {
        return mb_substr(implode(' | ', array_column($exceptions, 'text')), 0, 1500);
    }

    public static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ' '), '0'), '.');
    }

    /** @return array{code: string, severity: string, item_id: ?int, text: string} */
    private function make(string $code, string $severity, ?int $itemId, string $text): array
    {
        return ['code' => $code, 'severity' => $severity, 'item_id' => $itemId, 'text' => $text];
    }

    /**
     * Item ids of ABC class A in the shop: 28-day net revenue (active, not storno, refunds subtract), items with an
     * order rule, A while the cumulative share is <= 80 % (same rule as ForecastService). Empty without sales.
     *
     * @return array<int, true>
     */
    private function classA(int $shopId): array
    {
        $today = now()->startOfDay();
        $rows = DB::table('receipt_items as ri')
            ->join('receipts', 'receipts.id', '=', 'ri.receipt_id')
            ->join('item_order_rules as r', function ($join) {
                $join->on('r.item_id', '=', 'ri.item_id')->on('r.shop_id', '=', 'receipts.shop_id');
            })
            ->where('r.max', '>', 0)
            ->where('receipts.active', true)
            ->where('ri.storno', false)
            ->where('receipts.shop_id', $shopId)
            ->where('receipts.created_at', '>=', $today->copy()->subDays(28)->toDateTimeString())
            ->where('receipts.created_at', '<', $today->toDateTimeString())
            ->groupBy('ri.item_id')
            ->selectRaw('ri.item_id as item_id, SUM(CASE WHEN receipts.sell = 1 THEN ri.total ELSE -ri.total END) as revenue')
            ->get();

        $revenue = $rows->mapWithKeys(fn ($r) => [(int) $r->item_id => max((float) $r->revenue, 0.0)])->filter(fn ($v) => $v > 0)->sortDesc();
        $sum = $revenue->sum();
        if ($sum <= 0) {
            return [];
        }

        $a = [];
        $cum = 0.0;
        foreach ($revenue as $itemId => $rev) {
            $cum += $rev;
            if ($cum / $sum <= 0.80 + 1e-9 || $cum === $rev) {
                $a[$itemId] = true;
            } else {
                break;
            }
        }

        return $a;
    }

    /** Average total pieces of the shop's confirmed orders in the history window; null below the minimum sample. */
    private function averageTotal(Order $order): ?float
    {
        $since = now()->subDays(max(1, (int) config('inventory.order_exception_history_days', 28)))->toDateTimeString();
        $totals = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.shop_id', $order->shop_id)
            ->where('orders.id', '!=', $order->id)
            ->whereIn('orders.status', [OrderStatus::Confirmed->value, OrderStatus::Approved->value, OrderStatus::Fulfilled->value])
            ->whereRaw('COALESCE(orders.confirmed_at, orders.created_at) >= ?', [$since])
            ->groupBy('orders.id')
            ->selectRaw('SUM(order_items.qty) as pieces')
            ->pluck('pieces');

        if ($totals->count() < max(1, (int) config('inventory.order_exception_min_orders', 3))) {
            return null;
        }
        $average = $totals->avg(fn ($p) => (float) $p);

        return $average > 0 ? (float) $average : null;
    }
}
