<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\ShopAccess;
use Illuminate\Support\Facades\DB;

/**
 * Ordering (replenishment) report: for each shop, the items that should be
 * ordered from the warehouse, calculated from the shop's min/max rules and the
 * current stock corrected by what has sold since the stock snapshot.
 *
 * Metric dictionary
 * -----------------
 * stock            current stock snapshot (Stock::applyCurrent: latest stock_date per item+shop, 1C's figure)
 * net sold         successful sales minus successful refunds on receipts dated on or after the
 *                  snapshot's stock_date (receipts.active = 1, storno lines excluded; sell = 1 adds,
 *                  sell = 0 subtracts). Counted from the snapshot DAY because stocks has no sync time.
 * estimated stock  stock - net sold, never below 0
 * min / max        item_order_rules for that shop+item (rules with max <= 0 are ignored)
 * order            max - estimated stock, only when estimated stock <= min and the result is > 0
 * level            'out' when estimated stock <= 0, otherwise 'low' (at or below min)
 *
 * The snapshot can be older than the lookback window; sales are then only counted
 * from the window start and the row is flagged `stale`.
 */
class OrderingReport
{
    /** Never look further back than this when adding up sales since an old snapshot. */
    public const LOOKBACK_DAYS = 31;

    /**
     * @param  array<int, int>|null  $shopIds  null = every shop
     * @param  array<int, int>  $excludeShopIds  shops that are never replenished (the main warehouse)
     * @return array{generated_at: string, total_items: int, shops: array<int, array<string, mixed>>}
     */
    public function build(?array $shopIds = null, array $excludeShopIds = []): array
    {
        $shops = ShopAccess::restrictTo(DB::table('shops'), 'shops.id', $shopIds)
            ->when($excludeShopIds !== [], fn ($q) => $q->whereNotIn('shops.id', $excludeShopIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        $scope = $shops->pluck('id')->map(fn ($id) => (int) $id)->all();

        $pairs = $scope === [] ? collect() : $this->pairs($scope);
        $sales = $pairs->isEmpty() ? [] : $this->netSalesByDay($scope, $pairs);

        $byShop = [];
        foreach ($pairs as $p) {
            $net = 0.0;
            foreach ($sales[$p->shop_id][$p->item_id] ?? [] as $day => $value) {
                if ($day >= $p->stock_date) {
                    $net += $value;
                }
            }

            $stock = (float) $p->qty;
            $estimated = max($stock - $net, 0.0);
            $min = (float) $p->min;
            $max = (float) $p->max;

            if ($estimated > $min) {
                continue;
            }

            $order = round($max - $estimated, 3);
            if ($order <= 0) {
                continue;
            }

            $byShop[$p->shop_id][] = [
                'item_id' => (int) $p->item_id,
                'item' => (string) $p->item_name,
                'group' => $p->group_name,
                'stock' => $stock,
                'stock_date' => (string) $p->stock_date,
                'net_sold' => round($net, 3),
                'estimated' => round($estimated, 3),
                'min' => $min,
                'max' => $max,
                'order' => $order,
                'level' => $estimated <= 0 ? 'out' : 'low',
                'stale' => $p->stock_date < now()->subDays(self::LOOKBACK_DAYS)->toDateString(),
            ];
        }

        $total = 0;
        $result = [];
        foreach ($shops as $shop) {
            $rows = $byShop[$shop->id] ?? [];

            // Most urgent first: out of stock, then lowest cover against the minimum, then biggest order.
            usort($rows, function (array $a, array $b) {
                return [$a['level'] === 'out' ? 0 : 1, $a['min'] > 0 ? $a['estimated'] / $a['min'] : 0, -$a['order']]
                    <=> [$b['level'] === 'out' ? 0 : 1, $b['min'] > 0 ? $b['estimated'] / $b['min'] : 0, -$b['order']];
            });

            $total += count($rows);
            $result[] = [
                'id' => (int) $shop->id,
                'name' => (string) $shop->name,
                'rows' => $rows,
                'count' => count($rows),
                'out' => count(array_filter($rows, fn ($r) => $r['level'] === 'out')),
            ];
        }

        return [
            'generated_at' => now()->format('H:i:s'),
            'total_items' => $total,
            'shops' => $result,
        ];
    }

    /**
     * Every (shop, item) pair that has a usable rule, with its current stock.
     *
     * @param  array<int, int>  $scope
     */
    private function pairs(array $scope)
    {
        return Stock::applyCurrent(DB::table('stocks'))
            ->join('item_order_rules as r', function ($join) {
                $join->on('r.item_id', '=', 'stocks.item_id')->on('r.shop_id', '=', 'stocks.shop_id');
            })
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
            ->whereIn('stocks.shop_id', $scope)
            ->where('r.max', '>', 0)
            ->get([
                'stocks.shop_id', 'stocks.item_id', 'stocks.qty', 'stocks.stock_date',
                'r.min', 'r.max', 'items.name as item_name', 'groups.name as group_name',
            ]);
    }

    /**
     * Net sales (sales - refunds) per shop, item and day, from the oldest snapshot
     * that matters (bounded by the lookback window) up to now.
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<int, array<string, float>>>
     */
    private function netSalesByDay(array $scope, $pairs): array
    {
        $floor = now()->subDays(self::LOOKBACK_DAYS)->startOfDay()->toDateString();
        $oldest = max((string) $pairs->min('stock_date'), $floor);

        $rows = DB::table('receipt_items as ri')
            ->join('receipts', 'receipts.id', '=', 'ri.receipt_id')
            ->where('receipts.active', true)
            ->where('ri.storno', false)
            ->where('receipts.created_at', '>=', $oldest.' 00:00:00')
            ->whereIn('receipts.shop_id', $scope)
            ->groupBy('receipts.shop_id', 'ri.item_id', 'd')
            ->selectRaw('receipts.shop_id as shop_id, ri.item_id as item_id, DATE(receipts.created_at) as d, SUM(CASE WHEN receipts.sell = 1 THEN ri.qty ELSE -ri.qty END) as net')
            ->get();

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(int) $r->shop_id][(int) $r->item_id][(string) $r->d] = (float) $r->net;
        }

        return $byDay;
    }
}
