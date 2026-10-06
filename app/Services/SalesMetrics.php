<?php

namespace App\Services;

use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales metrics for the live dashboards (SalesBoard and Monitor): today vs the
 * previous day up to the same time, hourly series, profit over cost-covered
 * lines, payment mix, refunds, 7-day trend and top items. Everything is
 * aggregated in SQL; successful sale = receipts.active=1 and sell=1, storno
 * lines excluded, profit only where the item has a cost price above 0.
 */
class SalesMetrics
{
    public const COLORS = [
        '#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#c23a3a', '#a13a7a',
    ];

    public const COLOR_OTHER = '#898781';

    public const TOP_ITEMS = 20;

    /**
     * @param  array<int, int>|null  $shopIds  restrict to these shops (null = all, see ShopAccess)
     * @return array<string, mixed>
     */
    public function board(Carbon $now, ?array $shopIds = null): array
    {
        $todayStart = $now->copy()->startOfDay();
        $yesterdayStart = $todayStart->copy()->subDay();
        $yesterdayCut = $now->copy()->subDay(); // same time of day, previous day

        $shops = ShopAccess::restrictTo(DB::table('shops')->orderBy('name'), 'id', $shopIds)->pluck('name', 'id')->all();

        // Per shop: today so far vs. the previous day up to the same time.
        $today = $this->shopTotals($todayStart, $now, $shopIds);
        $yesterday = $this->shopTotals($yesterdayStart, $yesterdayCut, $shopIds);

        $shopIds = array_unique(array_merge(array_keys($today), array_keys($yesterday)));
        usort($shopIds, fn ($a, $b) => ($today[$b]['sum'] ?? 0) <=> ($today[$a]['sum'] ?? 0));

        $colors = [];
        foreach ($shopIds as $i => $id) {
            $colors[$id] = self::COLORS[$i] ?? self::COLOR_OTHER;
        }

        $table = [];
        $totals = ['count' => 0, 'sum' => 0.0, 'y_count' => 0, 'y_sum' => 0.0];
        foreach ($shopIds as $id) {
            $row = [
                'name' => $shops[$id] ?? '#'.$id,
                'color' => $colors[$id],
                'count' => $today[$id]['count'] ?? 0,
                'sum' => $today[$id]['sum'] ?? 0.0,
                'y_count' => $yesterday[$id]['count'] ?? 0,
                'y_sum' => $yesterday[$id]['sum'] ?? 0.0,
            ];
            $row['count_delta'] = $this->delta($row['count'], $row['y_count']);
            $row['sum_delta'] = $this->delta($row['sum'], $row['y_sum']);
            $table[] = $row;

            $totals['count'] += $row['count'];
            $totals['sum'] += $row['sum'];
            $totals['y_count'] += $row['y_count'];
            $totals['y_sum'] += $row['y_sum'];
        }
        $totals['count_delta'] = $this->delta($totals['count'], $totals['y_count']);
        $totals['sum_delta'] = $this->delta($totals['sum'], $totals['y_sum']);
        $totals['avg'] = $totals['count'] > 0 ? $totals['sum'] / $totals['count'] : 0.0;
        $totals['y_avg'] = $totals['y_count'] > 0 ? $totals['y_sum'] / $totals['y_count'] : 0.0;
        $totals['avg_delta'] = $this->delta($totals['avg'], $totals['y_avg']);

        return [
            'asOf' => $now->format('H:i'),
            'table' => $table,
            'totals' => $totals,
            'legend' => array_map(fn ($r) => ['name' => $r['name'], 'color' => $r['color']], $table),
            'charts' => $this->hourly($todayStart, $yesterdayStart, $shops, $colors, $shopIds),
            'topItems' => $this->topItems($todayStart, $now, $shops, $colors, $shopIds),
            'profit' => $this->profitSummary($todayStart, $now, $yesterdayStart, $yesterdayCut, $shops, $colors, $shopIds),
            'payments' => $this->paymentMix($todayStart, $now, $shopIds),
            'refunds' => $this->refunds($todayStart, $now, $shopIds),
            'trend' => $this->trend($todayStart, $shopIds),
        ];
    }

    /**
     * Successful sale receipts per shop in a window.
     *
     * @return array<int, array{count: int, sum: float}>
     */
    public function shopTotals(Carbon $from, Carbon $to, ?array $shopIds = null): array
    {
        return ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)
            ->where('active', true)
            ->where('sell', true)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('shop_id')
            ->selectRaw('shop_id, COUNT(*) as c, SUM(total) as s')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->shop_id => ['count' => (int) $r->c, 'sum' => (float) $r->s]])
            ->all();
    }

    /**
     * Hourly series for both metrics: today stacked by shop, previous day as a marker.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function hourly(Carbon $todayStart, Carbon $yesterdayStart, array $shops, array $colors, ?array $shopIds = null): array
    {
        $hourExpr = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $rows = ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)
            ->where('active', true)
            ->where('sell', true)
            ->whereBetween('created_at', [$yesterdayStart, $todayStart->copy()->endOfDay()])
            ->groupBy('shop_id', 'is_today', 'hr')
            ->selectRaw("shop_id, CASE WHEN created_at >= ? THEN 1 ELSE 0 END as is_today, {$hourExpr} as hr, COUNT(*) as c, SUM(total) as s", [$todayStart])
            ->get();

        $series = [];
        foreach (['sum' => 's', 'count' => 'c'] as $metric => $col) {
            $todayBy = [];
            $yesterdayBy = [];
            foreach ($rows as $r) {
                $value = (float) $r->{$col};
                if ($r->is_today) {
                    $todayBy[(int) $r->hr][(int) $r->shop_id] = $value;
                } else {
                    $yesterdayBy[(int) $r->hr] = ($yesterdayBy[(int) $r->hr] ?? 0) + $value;
                }
            }

            $max = 0.0;
            for ($h = 0; $h < 24; $h++) {
                $max = max($max, array_sum($todayBy[$h] ?? []), $yesterdayBy[$h] ?? 0);
            }

            $hours = [];
            for ($h = 0; $h < 24; $h++) {
                $total = array_sum($todayBy[$h] ?? []);
                $segments = [];
                foreach ($todayBy[$h] ?? [] as $shopId => $value) {
                    $segments[] = [
                        'name' => $shops[$shopId] ?? '#'.$shopId,
                        'color' => $colors[$shopId] ?? self::COLOR_OTHER,
                        'percent' => $total > 0 ? $value / $total * 100 : 0,
                        'label' => number_format($value, 0, '.', ' '),
                    ];
                }
                $y = $yesterdayBy[$h] ?? 0;
                $hours[] = [
                    'hour' => sprintf('%02d', $h),
                    'height' => $max > 0 ? $total / $max * 100 : 0,
                    'marker' => $max > 0 ? $y / $max * 100 : 0,
                    'total' => $total,
                    'y_total' => $y,
                    'today' => number_format($total, 0, '.', ' '),
                    'yesterday' => number_format($y, 0, '.', ' '),
                    'segments' => $segments,
                ];
            }
            $series[$metric] = $hours;
        }

        return $series;
    }

    /**
     * Best-selling items today (successful sale receipts, storno lines
     * excluded), ranked by quantity: overall and per shop.
     *
     * @return array{all: array, shops: array}
     */
    public function topItems(Carbon $from, Carbon $to, array $shops, array $colors, ?array $shopIds = null): array
    {
        $rows = ShopAccess::restrictTo(DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id'), 'receipts.shop_id', $shopIds)
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->tap(fn ($q) => $this->joinCost($q))
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->groupBy('receipts.shop_id', 'receipt_items.item_id')
            ->selectRaw('receipts.shop_id, receipt_items.item_id, SUM(receipt_items.qty) as q, SUM(receipt_items.total) as s, '.$this->profitSql().' as p')
            ->get();

        $overall = [];
        $perShop = [];
        foreach ($rows as $r) {
            $o = &$overall[$r->item_id];
            $p = $r->p === null ? null : (float) $r->p;
            $o = [
                'qty' => ($o['qty'] ?? 0) + (float) $r->q,
                'sum' => ($o['sum'] ?? 0) + (float) $r->s,
                'profit' => $p === null ? ($o['profit'] ?? null) : ($o['profit'] ?? 0) + $p,
            ];
            unset($o);
            $perShop[$r->shop_id][$r->item_id] = ['qty' => (float) $r->q, 'sum' => (float) $r->s, 'profit' => $p];
        }

        $rank = function (array $items) {
            uasort($items, fn ($a, $b) => [$b['qty'], $b['sum']] <=> [$a['qty'], $a['sum']]);

            return array_slice($items, 0, self::TOP_ITEMS, true);
        };

        $overall = $rank($overall);
        $perShop = array_map($rank, $perShop);

        $ids = array_keys($overall);
        foreach ($perShop as $items) {
            $ids = array_merge($ids, array_keys($items));
        }
        $names = DB::table('items')->whereIn('id', array_unique($ids))->pluck('name', 'id');

        $format = fn (array $items) => array_values(array_map(fn ($id, $v) => [
            'name' => $names[$id] ?? '#'.$id,
            'qty' => rtrim(rtrim(number_format($v['qty'], 3, '.', ' '), '0'), '.'),
            'sum' => number_format($v['sum'], 0, '.', ' '),
            'profit' => $v['profit'] === null ? null : number_format($v['profit'], 0, '.', ' '),
            'profit_negative' => $v['profit'] !== null && $v['profit'] < 0,
        ], array_keys($items), $items));

        $shopTabs = [];
        foreach ($colors as $shopId => $color) {
            if (isset($perShop[$shopId])) {
                $shopTabs[] = ['id' => $shopId, 'name' => $shops[$shopId] ?? '#'.$shopId, 'items' => $format($perShop[$shopId])];
            }
        }

        return ['all' => $format($overall), 'shops' => $shopTabs];
    }

    /** LEFT JOIN of the item's cost price (price id from config) onto a receipt_items query. */
    public function joinCost($query): void
    {
        $query->leftJoin('item_prices as cost', function ($join) {
            $join->on('cost.item_id', '=', 'receipt_items.item_id')
                ->where('cost.price_id', '=', (int) config('inventory.cost_price_id'));
        });
    }

    /** Profit over lines whose item has a cost > 0 (NULL when no line is covered). */
    public function profitSql(): string
    {
        return 'SUM(CASE WHEN cost.value > 0 THEN receipt_items.total - receipt_items.qty * cost.value END)';
    }

    /**
     * Profit of successful sales in both windows, per shop and in total.
     * Only lines whose item has a cost > 0 count; the uncovered share of
     * revenue and the number of cost-less items are reported alongside.
     *
     * @return array<string, mixed>
     */
    public function profitSummary(Carbon $from, Carbon $to, Carbon $yFrom, Carbon $yTo, array $shops, array $colors, ?array $shopIds = null): array
    {
        $today = $this->profitByShop($from, $to, $shopIds);
        $yesterday = $this->profitByShop($yFrom, $yTo, $shopIds);

        $sum = fn (array $rows, string $k) => array_sum(array_column($rows, $k));
        $tProfit = $sum($today, 'profit');
        $tCovered = $sum($today, 'covered');
        $tRevenue = $sum($today, 'revenue');
        $yProfit = $sum($yesterday, 'profit');

        $empty = ['profit' => 0.0, 'covered' => 0.0, 'revenue' => 0.0];
        $rows = [];
        foreach ($colors as $id => $color) {
            $t = $today[$id] ?? $empty;
            $y = $yesterday[$id] ?? $empty;
            $rows[] = [
                'name' => $shops[$id] ?? '#'.$id,
                'color' => $color,
                'profit' => $t['profit'],
                'y_profit' => $y['profit'],
                'delta' => $this->delta($t['profit'], $y['profit']),
                'margin' => $t['covered'] > 0 ? $t['profit'] / $t['covered'] * 100 : null,
            ];
        }

        $missingItems = (int) ShopAccess::restrictTo(DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id'), 'receipts.shop_id', $shopIds)
            ->tap(fn ($q) => $this->joinCost($q))
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->where(fn ($q) => $q->whereNull('cost.value')->orWhere('cost.value', '<=', 0))
            ->distinct()
            ->count('receipt_items.item_id');

        return [
            'total' => $tProfit,
            'yesterday' => $yProfit,
            'delta' => $this->delta($tProfit, $yProfit),
            'margin' => $tCovered > 0 ? $tProfit / $tCovered * 100 : null,
            'uncovered_percent' => $tRevenue > 0 ? round(($tRevenue - $tCovered) / $tRevenue * 100, 1) : 0.0,
            'missing_items' => $missingItems,
            'shops' => $rows,
        ];
    }

    /**
     * @return array<int, array{profit: float, covered: float, revenue: float}>
     */
    public function profitByShop(Carbon $from, Carbon $to, ?array $shopIds = null): array
    {
        return ShopAccess::restrictTo(DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id'), 'receipts.shop_id', $shopIds)
            ->tap(fn ($q) => $this->joinCost($q))
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->groupBy('receipts.shop_id')
            ->selectRaw(
                'receipts.shop_id, SUM(receipt_items.total) as rev, '
                .'SUM(CASE WHEN cost.value > 0 THEN receipt_items.total END) as cov, '.$this->profitSql().' as p'
            )
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->shop_id => [
                'profit' => (float) $r->p,
                'covered' => (float) $r->cov,
                'revenue' => (float) $r->rev,
            ]])
            ->all();
    }

    /**
     * Today's payment-type mix (successful sales).
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentMix(Carbon $from, Carbon $to, ?array $shopIds = null): array
    {
        $rows = ShopAccess::restrictTo(DB::table('receipt_payments')
            ->join('receipts', 'receipts.id', '=', 'receipt_payments.receipt_id'), 'receipts.shop_id', $shopIds)
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->groupBy('receipt_payments.payment')
            ->selectRaw('receipt_payments.payment as name, SUM(receipt_payments.value) as s')
            ->orderByDesc('s')
            ->get();

        $total = (float) $rows->sum('s');

        return $rows->values()->map(fn ($r, $i) => [
            'name' => (string) $r->name,
            'sum' => number_format((float) $r->s, 0, '.', ' '),
            'percent' => $total > 0 ? (float) $r->s / $total * 100 : 0.0,
            'color' => self::COLORS[$i] ?? self::COLOR_OTHER,
        ])->all();
    }

    /**
     * Refund receipts (sell = 0, active) in the window.
     *
     * @return array{count: int, sum: float}
     */
    public function refunds(Carbon $from, Carbon $to, ?array $shopIds = null): array
    {
        $r = ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)
            ->where('active', true)
            ->where('sell', false)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as c, SUM(total) as s')
            ->first();

        return ['count' => (int) $r->c, 'sum' => abs((float) $r->s)];
    }

    /**
     * Last 7 days including today: revenue and profit (cost-covered lines) per day.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(Carbon $todayStart, ?array $shopIds = null): array
    {
        $from = $todayStart->copy()->subDays(6);
        $to = $todayStart->copy()->endOfDay();

        $rev = ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)
            ->where('active', true)
            ->where('sell', true)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c, SUM(total) as s')
            ->get()
            ->keyBy('d');

        $profit = ShopAccess::restrictTo(DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id'), 'receipts.shop_id', $shopIds)
            ->tap(fn ($q) => $this->joinCost($q))
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->groupBy(DB::raw('DATE(receipts.created_at)'))
            ->selectRaw('DATE(receipts.created_at) as d, '.$this->profitSql().' as p')
            ->get()
            ->keyBy('d');

        $days = [];
        $max = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $day = $from->copy()->addDays($i);
            $key = $day->toDateString();
            $r = (float) ($rev[$key]->s ?? 0);
            $p = (float) ($profit[$key]->p ?? 0);
            $max = max($max, $r, $p);
            $days[] = ['label' => $day->format('d.m'), 'revenue' => $r, 'profit' => $p, 'count' => (int) ($rev[$key]->c ?? 0)];
        }

        foreach ($days as &$d) {
            $d['revenue_h'] = $max > 0 ? max($d['revenue'], 0) / $max * 100 : 0;
            $d['profit_h'] = $max > 0 ? max($d['profit'], 0) / $max * 100 : 0;
            $d['revenue_label'] = number_format($d['revenue'], 0, '.', ' ');
            $d['profit_label'] = number_format($d['profit'], 0, '.', ' ');
        }
        unset($d);

        return $days;
    }

    public function delta(float|int $current, float|int $previous): ?float
    {
        return $previous > 0 ? ($current - $previous) / $previous * 100 : null;
    }
}
