<?php

namespace App\Services;

use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deep receipt analytics for a date range and a set of shops.
 *
 * Definitions (same as ReceiptsMetrics): sale receipt = active & sell,
 * refund = active & !sell, cancelled = !active. Receipts without lines are
 * valid (cancelled ones often are), so receipt counts always come from
 * `receipts`, never from `receipt_items`. Discount of a receipt =
 * receipts.discount (gross - total); discount rate = discount / (total + discount).
 * "Big" receipt = sale receipt at or above the 95th percentile of sale totals
 * in the selected range and shops (a data-driven cut, not a magic number).
 */
class ReceiptAnalytics
{
    /** Percentile (share of receipts from the top) that defines a "big" receipt. */
    public const BIG_TOP_SHARE = 0.05;

    /** Items considered for relations; the pair self-join is limited to them. */
    private const RELATION_ITEMS = 40;

    /** A pair needs at least this many shared receipts to be reported. */
    private const MIN_PAIR_SUPPORT = 5;

    /**
     * @param  array<int, int>  $shopIds  already intersected with the viewer's scope; [] = none
     */
    public function build(Carbon $from, Carbon $to, array $shopIds): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        if ($shopIds === []) {
            return [];
        }

        $kpi = $this->kpi($from, $to, $shopIds);
        $big = $this->bigReceipts($from, $to, $shopIds, $kpi);

        return [
            'kpi' => $kpi,
            'discountBuckets' => $this->discountBuckets($from, $to, $shopIds),
            'discountByShop' => $this->discountByShop($from, $to, $shopIds),
            'discountItems' => $this->discountItems($from, $to, $shopIds),
            'big' => $big,
            'bigItems' => $big['cut'] === null ? [] : $this->bigReceiptItems($from, $to, $shopIds, $big['cut']),
            'relations' => $this->relations($from, $to, $shopIds),
        ];
    }

    private function receipts(Carbon $from, Carbon $to, array $shopIds)
    {
        return ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->whereBetween('receipts.created_at', [$from, $to]);
    }

    private function sales(Carbon $from, Carbon $to, array $shopIds)
    {
        return $this->receipts($from, $to, $shopIds)->where('receipts.active', true)->where('receipts.sell', true);
    }

    private function kpi(Carbon $from, Carbon $to, array $shopIds): array
    {
        $row = $this->receipts($from, $to, $shopIds)->selectRaw(
            'SUM(CASE WHEN active = 1 AND sell = 1 THEN 1 ELSE 0 END) as sale_count,
             COALESCE(SUM(CASE WHEN active = 1 AND sell = 1 THEN total ELSE 0 END), 0) as sale_sum,
             COALESCE(SUM(CASE WHEN active = 1 AND sell = 1 THEN discount ELSE 0 END), 0) as discount_sum,
             SUM(CASE WHEN active = 1 AND sell = 0 THEN 1 ELSE 0 END) as refund_count,
             COALESCE(SUM(CASE WHEN active = 1 AND sell = 0 THEN ABS(total) ELSE 0 END), 0) as refund_sum,
             SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) as cancelled_count,
             COUNT(*) as all_count'
        )->first();

        $saleCount = (int) $row->sale_count;
        $saleSum = (float) $row->sale_sum;
        $discount = (float) $row->discount_sum;
        $gross = $saleSum + $discount;

        $lines = $saleCount === 0 ? 0 : (int) $this->sales($from, $to, $shopIds)
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false)
            ->count('receipt_items.id');

        return [
            'sale_count' => $saleCount,
            'sale_sum' => $saleSum,
            'avg_check' => $saleCount ? $saleSum / $saleCount : 0.0,
            'basket' => $saleCount ? $lines / $saleCount : 0.0,
            'discount_sum' => $discount,
            'discount_rate' => $gross > 0 ? $discount / $gross * 100 : 0.0,
            'refund_count' => (int) $row->refund_count,
            'refund_sum' => (float) $row->refund_sum,
            'cancelled_count' => (int) $row->cancelled_count,
            'all_count' => (int) $row->all_count,
        ];
    }

    /** Sale receipts grouped by how deep their discount is. */
    private function discountBuckets(Carbon $from, Carbon $to, array $shopIds): array
    {
        $rate = '(CASE WHEN receipts.total + receipts.discount > 0 THEN receipts.discount * 100.0 / (receipts.total + receipts.discount) ELSE 0 END)';

        $rows = $this->sales($from, $to, $shopIds)->selectRaw(
            "CASE WHEN {$rate} <= 0 THEN 0
                  WHEN {$rate} < 5 THEN 1
                  WHEN {$rate} < 10 THEN 2
                  WHEN {$rate} < 20 THEN 3
                  WHEN {$rate} < 50 THEN 4
                  ELSE 5 END as bucket,
             COUNT(*) as cnt, COALESCE(SUM(receipts.total), 0) as revenue, COALESCE(SUM(receipts.discount), 0) as discount"
        )->groupBy('bucket')->get()->keyBy('bucket');

        $labels = [0 => 'No discount', 1 => '0-5 %', 2 => '5-10 %', 3 => '10-20 %', 4 => '20-50 %', 5 => '50 % and more'];
        $total = (int) $rows->sum('cnt');

        return collect($labels)->map(fn ($label, $key) => [
            'label' => $label,
            'count' => (int) ($rows[$key]->cnt ?? 0),
            'share' => $total ? (int) ($rows[$key]->cnt ?? 0) / $total * 100 : 0.0,
            'revenue' => (float) ($rows[$key]->revenue ?? 0),
            'discount' => (float) ($rows[$key]->discount ?? 0),
        ])->values()->all();
    }

    private function discountByShop(Carbon $from, Carbon $to, array $shopIds): array
    {
        return $this->sales($from, $to, $shopIds)
            ->join('shops', 'shops.id', '=', 'receipts.shop_id')
            ->groupBy('shops.id', 'shops.name')
            ->selectRaw('shops.name as shop, COUNT(*) as cnt,
                COALESCE(SUM(receipts.total), 0) as revenue, COALESCE(SUM(receipts.discount), 0) as discount,
                SUM(CASE WHEN receipts.discount > 0 THEN 1 ELSE 0 END) as discounted')
            ->orderByDesc('discount')
            ->get()
            ->map(function ($r) {
                $gross = (float) $r->revenue + (float) $r->discount;

                return [
                    'shop' => $r->shop,
                    'count' => (int) $r->cnt,
                    'revenue' => (float) $r->revenue,
                    'discount' => (float) $r->discount,
                    'rate' => $gross > 0 ? (float) $r->discount / $gross * 100 : 0.0,
                    'discounted_share' => $r->cnt ? (int) $r->discounted / (int) $r->cnt * 100 : 0.0,
                ];
            })->all();
    }

    /** Items that carry the most discount value (line discount = sum - total). */
    private function discountItems(Carbon $from, Carbon $to, array $shopIds): array
    {
        return $this->sales($from, $to, $shopIds)
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->join('items', 'items.id', '=', 'receipt_items.item_id')
            ->where('receipt_items.storno', false)
            ->groupBy('items.id', 'items.name')
            ->selectRaw('items.name as item, SUM(receipt_items.discount) as discount, SUM(receipt_items.total) as revenue,
                SUM(receipt_items.qty) as qty, COUNT(DISTINCT receipts.id) as receipts_count')
            ->havingRaw('SUM(receipt_items.discount) > 0')
            ->orderByDesc('discount')
            ->limit(15)
            ->get()
            ->map(function ($r) {
                $gross = (float) $r->revenue + (float) $r->discount;

                return [
                    'item' => $r->item,
                    'discount' => (float) $r->discount,
                    'revenue' => (float) $r->revenue,
                    'qty' => (float) $r->qty,
                    'receipts' => (int) $r->receipts_count,
                    'rate' => $gross > 0 ? (float) $r->discount / $gross * 100 : 0.0,
                ];
            })->all();
    }

    private function bigReceipts(Carbon $from, Carbon $to, array $shopIds, array $kpi): array
    {
        $count = $kpi['sale_count'];
        $empty = ['cut' => null, 'count' => 0, 'revenue_share' => 0.0, 'avg_check' => 0.0, 'normal_avg_check' => $kpi['avg_check'],
            'discount_rate' => 0.0, 'normal_discount_rate' => $kpi['discount_rate'], 'top' => []];

        // Too few receipts for a percentile to mean anything.
        if ($count < 20) {
            return $empty;
        }

        $offset = max(0, (int) floor($count * self::BIG_TOP_SHARE) - 1);
        $cut = $this->sales($from, $to, $shopIds)->orderByDesc('receipts.total')->offset($offset)->limit(1)->value('receipts.total');

        if ($cut === null || (float) $cut <= 0) {
            return $empty;
        }

        $cut = (float) $cut;

        $big = $this->sales($from, $to, $shopIds)->where('receipts.total', '>=', $cut)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(discount), 0) as discount')->first();

        $bigRevenue = (float) $big->revenue;
        $bigDiscount = (float) $big->discount;
        $normalRevenue = $kpi['sale_sum'] - $bigRevenue;
        $normalDiscount = $kpi['discount_sum'] - $bigDiscount;
        $normalCount = $count - (int) $big->cnt;

        $top = $this->sales($from, $to, $shopIds)
            ->join('shops', 'shops.id', '=', 'receipts.shop_id')
            ->where('receipts.total', '>=', $cut)
            ->orderByDesc('receipts.total')
            ->limit(20)
            ->get(['receipts.id', 'receipts.number', 'receipts.cashier', 'receipts.total', 'receipts.discount', 'receipts.created_at', 'shops.name as shop']);

        return [
            'cut' => $cut,
            'count' => (int) $big->cnt,
            'revenue_share' => $kpi['sale_sum'] > 0 ? $bigRevenue / $kpi['sale_sum'] * 100 : 0.0,
            'avg_check' => $big->cnt ? $bigRevenue / (int) $big->cnt : 0.0,
            'normal_avg_check' => $normalCount ? $normalRevenue / $normalCount : 0.0,
            'discount_rate' => $bigRevenue + $bigDiscount > 0 ? $bigDiscount / ($bigRevenue + $bigDiscount) * 100 : 0.0,
            'normal_discount_rate' => $normalRevenue + $normalDiscount > 0 ? $normalDiscount / ($normalRevenue + $normalDiscount) * 100 : 0.0,
            'top' => $top->map(fn ($r) => [
                'id' => (int) $r->id,
                'number' => $r->number,
                'shop' => $r->shop,
                'cashier' => $r->cashier,
                'total' => (float) $r->total,
                'discount' => (float) $r->discount,
                'created_at' => $r->created_at,
            ])->all(),
        ];
    }

    /**
     * Items over-represented in big receipts: share of big-receipt revenue vs
     * share of all sale revenue. index > 1 means the item is a big-basket item.
     */
    private function bigReceiptItems(Carbon $from, Carbon $to, array $shopIds, float $cut): array
    {
        $per = fn (bool $bigOnly) => $this->sales($from, $to, $shopIds)
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false)
            ->when($bigOnly, fn ($q) => $q->where('receipts.total', '>=', $cut))
            ->groupBy('receipt_items.item_id')
            ->selectRaw('receipt_items.item_id, SUM(receipt_items.total) as revenue, SUM(receipt_items.qty) as qty, SUM(receipt_items.discount) as discount, COUNT(DISTINCT receipts.id) as receipts_count')
            ->get()->keyBy('item_id');

        $big = $per(true);
        $all = $per(false);
        $bigTotal = (float) $big->sum('revenue');
        $allTotal = (float) $all->sum('revenue');

        if ($bigTotal <= 0 || $allTotal <= 0) {
            return [];
        }

        $names = DB::table('items')->whereIn('id', $big->sortByDesc('revenue')->take(60)->keys())->pluck('name', 'id');

        return $big->sortByDesc('revenue')->take(20)->map(function ($r, $itemId) use ($all, $bigTotal, $allTotal, $names) {
            $bigShare = (float) $r->revenue / $bigTotal * 100;
            $allShare = (float) ($all[$itemId]->revenue ?? 0) / $allTotal * 100;

            return [
                'item' => $names[$itemId] ?? '#'.$itemId,
                'revenue' => (float) $r->revenue,
                'qty' => (float) $r->qty,
                'receipts' => (int) $r->receipts_count,
                'big_share' => $bigShare,
                'all_share' => $allShare,
                'index' => $allShare > 0 ? $bigShare / $allShare : null,
            ];
        })->values()->all();
    }

    /**
     * Items bought together: support = receipts containing both, confidence =
     * support / receipts with A, lift = P(A&B) / (P(A) x P(B)). Limited to the
     * most frequent items first so the self-join stays cheap.
     */
    private function relations(Carbon $from, Carbon $to, array $shopIds): array
    {
        $lines = fn () => $this->sales($from, $to, $shopIds)
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false);

        $total = $this->sales($from, $to, $shopIds)->count();
        if ($total < 20) {
            return ['pairs' => [], 'total' => $total];
        }

        $top = $lines()->groupBy('receipt_items.item_id')
            ->selectRaw('receipt_items.item_id, COUNT(DISTINCT receipts.id) as cnt')
            ->orderByDesc('cnt')->limit(self::RELATION_ITEMS)->pluck('cnt', 'item_id');

        if ($top->count() < 2) {
            return ['pairs' => [], 'total' => $total];
        }

        $ids = $top->keys()->all();

        $pairs = $this->sales($from, $to, $shopIds)
            ->join('receipt_items as a', 'a.receipt_id', '=', 'receipts.id')
            ->join('receipt_items as b', 'b.receipt_id', '=', 'receipts.id')
            ->where('a.storno', false)->where('b.storno', false)
            ->whereColumn('a.item_id', '<', 'b.item_id')
            ->whereIn('a.item_id', $ids)->whereIn('b.item_id', $ids)
            ->groupBy('a.item_id', 'b.item_id')
            ->selectRaw('a.item_id as a_id, b.item_id as b_id, COUNT(DISTINCT receipts.id) as together')
            ->havingRaw('COUNT(DISTINCT receipts.id) >= ?', [self::MIN_PAIR_SUPPORT])
            ->orderByDesc('together')
            ->limit(200)
            ->get();

        $names = DB::table('items')->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $pairs->map(function ($p) use ($top, $total, $names) {
            $a = (int) $top[$p->a_id];
            $b = (int) $top[$p->b_id];
            $t = (int) $p->together;

            return [
                'a' => $names[$p->a_id] ?? '#'.$p->a_id,
                'b' => $names[$p->b_id] ?? '#'.$p->b_id,
                'together' => $t,
                'support' => $t / $total * 100,
                'conf_ab' => $t / $a * 100,
                'conf_ba' => $t / $b * 100,
                'lift' => $t * $total / ($a * $b),
            ];
        })->sortByDesc('lift')->take(25)->values()->all();

        return ['pairs' => $rows, 'total' => $total, 'min_support' => self::MIN_PAIR_SUPPORT, 'items' => $top->count()];
    }
}
