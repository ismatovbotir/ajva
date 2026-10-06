<?php

namespace App\Livewire;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Live sales board for the dashboard: today's successful sale receipts by
 * shop and hour compared with the previous day, plus the day's top items.
 * Polled once a minute from the view.
 */
class SalesBoard extends Component
{
    private const COLORS = [
        '#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#c23a3a', '#a13a7a',
    ];

    private const COLOR_OTHER = '#898781';

    private const TOP_ITEMS = 20;

    /** Slightly under the poll interval so every poll sees fresh data but concurrent viewers share one computation. */
    private const CACHE_TTL = 50;

    public function render()
    {
        $now = now();

        return view('livewire.sales-board', Cache::remember(
            'dashboard.sales-board.'.$now->toDateString(),
            self::CACHE_TTL,
            fn () => $this->build($now->copy()),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Carbon $now): array
    {
        $todayStart = $now->copy()->startOfDay();
        $yesterdayStart = $todayStart->copy()->subDay();
        $yesterdayCut = $now->copy()->subDay(); // same time of day, previous day

        $shops = DB::table('shops')->orderBy('name')->pluck('name', 'id')->all();

        // Per shop: today so far vs. the previous day up to the same time.
        $today = $this->shopTotals($todayStart, $now);
        $yesterday = $this->shopTotals($yesterdayStart, $yesterdayCut);

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

        return [
            'asOf' => $now->format('H:i'),
            'table' => $table,
            'totals' => $totals,
            'legend' => array_map(fn ($r) => ['name' => $r['name'], 'color' => $r['color']], $table),
            'charts' => $this->hourly($todayStart, $yesterdayStart, $shops, $colors),
            'topItems' => $this->topItems($todayStart, $now, $shops, $colors),
        ];
    }

    /**
     * Successful sale receipts per shop in a window.
     *
     * @return array<int, array{count: int, sum: float}>
     */
    private function shopTotals(Carbon $from, Carbon $to): array
    {
        return DB::table('receipts')
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
    private function hourly(Carbon $todayStart, Carbon $yesterdayStart, array $shops, array $colors): array
    {
        $hourExpr = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $rows = DB::table('receipts')
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
    private function topItems(Carbon $from, Carbon $to, array $shops, array $colors): array
    {
        $rows = DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->groupBy('receipts.shop_id', 'receipt_items.item_id')
            ->selectRaw('receipts.shop_id, receipt_items.item_id, SUM(receipt_items.qty) as q, SUM(receipt_items.total) as s')
            ->get();

        $overall = [];
        $perShop = [];
        foreach ($rows as $r) {
            $o = &$overall[$r->item_id];
            $o = ['qty' => ($o['qty'] ?? 0) + (float) $r->q, 'sum' => ($o['sum'] ?? 0) + (float) $r->s];
            unset($o);
            $perShop[$r->shop_id][$r->item_id] = ['qty' => (float) $r->q, 'sum' => (float) $r->s];
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
        ], array_keys($items), $items));

        $shopTabs = [];
        foreach ($colors as $shopId => $color) {
            if (isset($perShop[$shopId])) {
                $shopTabs[] = ['id' => $shopId, 'name' => $shops[$shopId] ?? '#'.$shopId, 'items' => $format($perShop[$shopId])];
            }
        }

        return ['all' => $format($overall), 'shops' => $shopTabs];
    }

    private function delta(float|int $current, float|int $previous): ?float
    {
        return $previous > 0 ? ($current - $previous) / $previous * 100 : null;
    }
}
