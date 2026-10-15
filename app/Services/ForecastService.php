<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderRule;
use App\Models\Stock;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one demand model behind the Ordering page and the warehouse monitor: a 1-7 day sales
 * forecast per (shop, item) pair that has an order rule (max > 0) and the order quantity for
 * every horizon. Four set-based queries (pairs with current stock, daily net sales, out-of-stock
 * snapshot days, open-order quantities); the only PHP work is one pass over the pairs.
 * OrderRecommendations::plan() turns the result into per-shop order lines and the warehouse pick list.
 *
 * Metric dictionary
 * -----------------
 * est (estimated)   snapshot qty (Stock::applyCurrent) minus net sold since the snapshot DAY, never below 0.
 *                   Net sold = sales - refunds on active receipts, storno lines excluded.
 * in_transit        quantity on open orders (pending_approval / approved, not fulfilled) of that shop and item:
 *                   goods that are on their way. position = est + in_transit; cover, stock-out, level, listing
 *                   and the order quantity all work on the position, so an item that was just ordered is not
 *                   suggested again.
 * history           the N COMPLETE days before today (N = the shop's order rule: 14, 21 or 28, default 28), starting at
 *                   max(today - N, the EARLIER of the pair's first sale and its first stock snapshot). Days without sales count as 0. A day is censored
 *                   (out of stock) when the stock snapshot of that date exists and is <= 0: censored days are
 *                   left out of every mean. Daily values are floored at 0 (refund-heavy days) and clipped at
 *                   median + 3 x MAD per pair (flag `clipped`; the MAD is floored at 10 % of the median, and
 *                   nothing is clipped for intermittent items whose median is 0).
 * speed (flat)      mean daily net sales over the non-censored days of the last 7 complete days; when fewer than
 *                   3 usable days, the last 14, then the last N (the ladder [7, 14, N] is cut at N). Fewer than 3
 *                   usable days in N = no speed.
 * weekday forecast  for a future day with weekday w: f = wd_mean[w] x L + flat x (1 - L), L = n_w / (n_w + 2),
 *                   wd_mean/n_w over the usable days of the chosen window with that weekday; floor 0.
 * f[1..7], F[1..7]  f[d] = forecast of the day d days from today (1 = tomorrow). F[H] = today_rem + f[1] + .. + f[H],
 *                   today_rem = max(f(today) - net sold today so far, 0) (the rest of today's demand).
 * order[1..7]       max(0, ceil(min + F[H] - position)): whole pieces when stock, min and max are whole, else up to
 *                   0.1. Rules-only items (no speed): max - position. Never decreases with H.
 * cover_days        position / speed (null without speed or with speed 0)
 * stockout_in/_date first d (0 = today, up to 7) where F[d] exceeds the position; today when position <= 0; else null
 * confidence        none (< 3 usable days: rules only) | low (3-9) | ok (>= 10) | high (>= 14 and every weekday >= 2)
 * level             out (position <= 0) | critical (cover < 1) | soon (cover < 3) | low (at/below min or cover < 7)
 *                   | over (above max or cover > 14) | ok
 * abc               per shop, items by 28-day net revenue (always 28 days, whatever the history window): A while the cumulative share is <= 80 %, B <= 95 %, else C
 * xyz               coefficient of variation of the daily demand: X < 0.5, Y < 1, else Z (null without speed)
 * listed[H]         position <= min OR cover < H OR stockout_in <= H, and order[H] > 0. Only pairs listed for the
 *                   largest horizon (7) are returned: listed[H] implies listed[7].
 */
class ForecastService
{
    public const HISTORY_DAYS = 28;

    public const MAX_HORIZON = 7;

    public const MIN_USABLE_DAYS = 3;

    /** Never add up sales further back than this since an old snapshot. */
    public const LOOKBACK_DAYS = 31;

    /** Usable days needed for confidence "ok" (below it: "low"). */
    public const OK_USABLE_DAYS = 10;

    /** Windows tried for the flat speed, newest first, cut at the shop's history window. */
    private const WINDOWS = [7, 14, 28];

    /** Clamp a requested horizon to 1..7; null = the chain default of the order rules (config order_horizon_days without one). */
    public static function horizon(?int $horizon = null): int
    {
        return max(1, min(self::MAX_HORIZON, $horizon ?? OrderRule::defaultHorizon()));
    }

    /**
     * @param  array<int, int>|null  $shopIds  null = every shop
     * @param  array<int, int>  $excludeShopIds  shops that are never replenished (the main warehouse)
     * @return array{generated_at: string, today: string, shops: array<int, array{id: int, name: string}>, rows: array<int, array<string, mixed>>}
     */
    public function build(?array $shopIds = null, array $excludeShopIds = [], ?Carbon $now = null): array
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();

        $shops = ShopAccess::restrictTo(DB::table('shops'), 'shops.id', $shopIds)
            ->when($excludeShopIds !== [], fn ($q) => $q->whereNotIn('shops.id', $excludeShopIds))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name']);
        $scope = $shops->pluck('id')->map(fn ($id) => (int) $id)->all();

        $result = [
            'generated_at' => $now->format('H:i:s'),
            'today' => $today->toDateString(),
            'shops' => $shops->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->all(),
            'rows' => [],
        ];

        if ($scope === []) {
            return $result;
        }

        // Shops are grouped by their history window (14 / 21 / 28 days, max 3 passes); the rule of each shop decides.
        $groups = [];
        foreach (OrderRule::historyDays($scope) as $shopId => $days) {
            $groups[$days][] = (int) $shopId;
        }
        ksort($groups);

        $all = [];
        foreach ($groups as $days => $group) {
            array_push($all, ...$this->rowsFor($group, (int) $days, $today));
        }

        $this->classifyAbc($all);

        foreach ($all as $row) {
            unset($row['revenue']);
            if ($row['listed'][self::MAX_HORIZON]) {
                $result['rows'][] = $row;
            }
        }

        return $result;
    }

    /**
     * The raw forecast rows (all pairs, unfiltered) of the shops that share one history window.
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(array $scope, int $days, Carbon $today): array
    {
        $pairs = $this->pairs($scope);
        if ($pairs->isEmpty()) {
            return [];
        }

        $sales = $this->dailySales($scope, $pairs, $today);
        $censored = $this->censoredDays($scope, $today);
        $transit = $this->inTransit($scope);

        // Calendar: yesterday .. $days back (index 0 = yesterday) and the 7 future days.
        $history = [];
        for ($i = 1; $i <= $days; $i++) {
            $day = $today->copy()->subDays($i);
            $history[$day->toDateString()] = ['idx' => $i - 1, 'wd' => $day->dayOfWeek];
        }
        $future = [];
        for ($d = 1; $d <= self::MAX_HORIZON; $d++) {
            $day = $today->copy()->addDays($d);
            $future[$d] = ['date' => $day->toDateString(), 'wd' => $day->dayOfWeek];
        }
        $ctx = [
            'today' => $today->toDateString(),
            'todayWd' => $today->dayOfWeek,
            'history' => $history,
            'future' => $future,
            'windowStart' => $today->copy()->subDays($days)->toDateString(),
            'abcFrom' => $today->copy()->subDays(self::HISTORY_DAYS)->toDateString(),
            'windows' => array_values(array_unique(array_filter([...self::WINDOWS, $days], fn (int $w) => $w <= $days))),
            'staleBefore' => $today->copy()->subDays(self::LOOKBACK_DAYS)->toDateString(),
            'factor' => (float) config('inventory.outlier_mad_factor', 3),
        ];

        $rows = [];
        foreach ($pairs as $p) {
            $rows[] = $this->pair(
                $p,
                $sales[$p->shop_id][$p->item_id] ?? [],
                $censored[$p->shop_id][$p->item_id] ?? [],
                $transit[$p->shop_id][$p->item_id] ?? 0.0,
                $ctx,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, array{0: float, 1: float}>  $sales  [net qty, net revenue] per day
     * @param  array<string, true>  $censoredDays
     * @return array<string, mixed>
     */
    private function pair(object $p, array $sales, array $censoredDays, float $transit, array $ctx): array
    {
        $stock = (float) $p->qty;
        $stockDate = substr((string) $p->stock_date, 0, 10);
        $min = (float) $p->min;
        $max = (float) $p->max;

        $netSold = 0.0;
        $revenue = 0.0;
        foreach ($sales as $day => [$net, $rev]) {
            if ($day >= $stockDate) {
                $netSold += $net;
            }
            if ($day >= $ctx['abcFrom'] && $day < $ctx['today']) {
                $revenue += $rev;
            }
        }
        $est = max($stock - $netSold, 0.0);
        $position = $est + $transit;
        $soldToday = max($sales[$ctx['today']][0] ?? 0.0, 0.0);

        // Observed days: since the earlier of first sale / first snapshot, at most 28 back.
        $first = $p->first_seen === null ? null : substr((string) $p->first_seen, 0, 10);
        $firstSale = $sales === [] ? null : min(array_keys($sales));
        $begin = $first === null ? $firstSale : ($firstSale === null ? $first : min($first, $firstSale));
        $start = $begin === null ? $ctx['today'] : max($begin, $ctx['windowStart']);

        $obs = [];       // usable days: [idx, weekday, value]
        $censoredCount = 0;
        foreach ($ctx['history'] as $day => $meta) {
            if ($day < $start) {
                continue;
            }
            if (isset($censoredDays[$day])) {
                $censoredCount++;

                continue;
            }
            $obs[] = [$meta['idx'], $meta['wd'], max($sales[$day][0] ?? 0.0, 0.0)];
        }

        $usable = count($obs);
        $clipped = false;
        if ($usable >= self::MIN_USABLE_DAYS) {
            $values = array_column($obs, 2);
            $median = $this->median($values);
            $mad = $this->median(array_map(fn ($v) => abs($v - $median), $values));
            // MAD = 0 (steady demand with a few deviations): the scale is floored at 10 % of the median, but
            // nothing is clipped for intermittent items (median 0), whose sporadic sales are real demand.
            $scale = max($mad, 0.1 * $median);
            if ($scale > 0) {
                $cap = $median + $ctx['factor'] * $scale;
                foreach ($obs as &$o) {
                    if ($o[2] > $cap) {
                        $o[2] = $cap;
                        $clipped = true;
                    }
                }
                unset($o);
            }
        }

        $flat = null;
        $wdSum = array_fill(0, 7, 0.0);
        $wdCnt = array_fill(0, 7, 0);
        if ($usable >= self::MIN_USABLE_DAYS) {
            foreach ($ctx['windows'] as $w) {
                $in = array_values(array_filter($obs, fn ($o) => $o[0] < $w));
                if (count($in) >= self::MIN_USABLE_DAYS) {
                    $flat = array_sum(array_column($in, 2)) / count($in);
                    foreach ($in as [, $wd, $v]) {
                        $wdSum[$wd] += $v;
                        $wdCnt[$wd]++;
                    }
                    break;
                }
            }
        }

        $weekdaysSeen = array_fill(0, 7, 0);
        foreach ($obs as [, $wd]) {
            $weekdaysSeen[$wd]++;
        }
        $confidence = $flat === null ? 'none'
            : ($usable >= 14 && min($weekdaysSeen) >= 2 ? 'high' : ($usable >= self::OK_USABLE_DAYS ? 'ok' : 'low'));

        $f = [];
        $cum = [];
        $todayRem = 0.0;
        if ($flat !== null) {
            $forecast = function (int $wd) use ($flat, $wdSum, $wdCnt): float {
                $n = $wdCnt[$wd];
                $lambda = $n / ($n + 2);
                $mean = $n > 0 ? $wdSum[$wd] / $n : 0.0;

                return max($mean * $lambda + $flat * (1 - $lambda), 0.0);
            };
            $todayRem = max($forecast($ctx['todayWd']) - $soldToday, 0.0);
            $running = $todayRem;
            for ($d = 1; $d <= self::MAX_HORIZON; $d++) {
                $f[$d] = $forecast($ctx['future'][$d]['wd']);
                $running += $f[$d];
                $cum[$d] = $running;
            }
        } else {
            for ($d = 1; $d <= self::MAX_HORIZON; $d++) {
                $f[$d] = 0.0;
                $cum[$d] = 0.0;
            }
        }

        $piece = $this->isWhole($stock) && $this->isWhole($min) && $this->isWhole($max);
        $order = [];
        for ($d = 1; $d <= self::MAX_HORIZON; $d++) {
            $need = $flat === null ? $max - $position : $min + $cum[$d] - $position;
            $order[$d] = $this->roundUp($need, $piece);
        }

        $cover = $flat !== null && $flat > 0 ? $position / $flat : null;

        $stockoutIn = null;
        if ($position <= 0) {
            $stockoutIn = 0;
        } elseif ($flat !== null) {
            if ($todayRem > $position) {
                $stockoutIn = 0;
            } else {
                for ($d = 1; $d <= self::MAX_HORIZON; $d++) {
                    if ($cum[$d] > $position) {
                        $stockoutIn = $d;
                        break;
                    }
                }
            }
        }

        $level = $position <= 0 ? 'out'
            : ($cover !== null && $cover < 1 ? 'critical'
            : ($cover !== null && $cover < 3 ? 'soon'
            : ($position <= $min || ($cover !== null && $cover < 7) ? 'low'
            : ($position > $max || ($cover !== null && $cover > 14) ? 'over' : 'ok'))));

        $listed = [];
        for ($h = 1; $h <= self::MAX_HORIZON; $h++) {
            $listed[$h] = ($position <= $min || ($cover !== null && $cover < $h) || ($stockoutIn !== null && $stockoutIn <= $h))
                && $order[$h] > 0;
        }

        $xyz = null;
        if ($flat !== null) {
            $values = array_column($obs, 2);
            $mean = array_sum($values) / count($values);
            $cv = $mean > 0 ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / count($values)) / $mean : INF;
            $xyz = $cv < 0.5 ? 'X' : ($cv < 1 ? 'Y' : 'Z');
        }

        return [
            'shop_id' => (int) $p->shop_id,
            'item_id' => (int) $p->item_id,
            'item' => (string) $p->item_name,
            'group' => $p->group_name,
            'piece' => $piece,
            'stock' => $stock,
            'stock_date' => $stockDate,
            'net_sold' => round($netSold, 3),
            'est' => round($est, 3),
            'in_transit' => round($transit, 3),
            'position' => round($position, 3),
            'min' => $min,
            'max' => $max,
            'speed' => $flat === null ? null : round($flat, 3),
            'rules_only' => $flat === null,
            'confidence' => $confidence,
            'usable_days' => $usable,
            'censored_days' => $censoredCount,
            'clipped' => $clipped,
            'stale' => $stockDate < $ctx['staleBefore'],
            'abc' => 'C',
            'xyz' => $xyz,
            'cover_days' => $cover === null ? null : round($cover, 1),
            'stockout_in' => $stockoutIn,
            'stockout_date' => $stockoutIn === null ? null : Carbon::parse($ctx['today'])->addDays($stockoutIn)->toDateString(),
            'level' => $level,
            'today_rem' => round($todayRem, 3),
            'f' => array_map(fn ($v) => round($v, 3), $f),
            'F' => array_map(fn ($v) => round($v, 3), $cum),
            'order' => $order,
            'listed' => $listed,
            'revenue' => $revenue,
        ];
    }

    /**
     * ABC per shop from the 28-day net revenue (A <= 80 % cumulative, B <= 95 %, else C).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function classifyAbc(array &$rows): void
    {
        $byShop = [];
        foreach ($rows as $i => $row) {
            $byShop[$row['shop_id']][$i] = max($row['revenue'], 0.0);
        }

        foreach ($byShop as $revenues) {
            $total = array_sum($revenues);
            if ($total <= 0) {
                continue;
            }
            arsort($revenues);
            $cum = 0.0;
            foreach ($revenues as $i => $rev) {
                if ($rev <= 0) {
                    break;
                }
                $cum += $rev;
                $share = $cum / $total;
                $rows[$i]['abc'] = $share <= 0.80 + 1e-9 || $cum === $rev ? 'A' : ($share <= 0.95 + 1e-9 ? 'B' : 'C');
            }
        }
    }

    /** @param  array<int, float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function isWhole(float $v): bool
    {
        return abs($v - round($v)) < 1e-9;
    }

    /** Round a quantity up: whole pieces for piece items, else to 0.1; never below 0. */
    private function roundUp(float $qty, bool $piece): float
    {
        if ($qty <= 1e-9) {
            return 0.0;
        }

        return $piece ? (float) ceil(round($qty, 6)) : ceil(round($qty * 10, 6)) / 10;
    }

    /**
     * Every (shop, item) pair that has a usable rule, with its current stock and the date of its first snapshot.
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
            ->select([
                'stocks.shop_id', 'stocks.item_id', 'stocks.qty', 'stocks.stock_date',
                'r.min', 'r.max', 'items.name as item_name', 'groups.name as group_name',
            ])
            ->selectSub(
                DB::table('stocks as s_first')
                    ->selectRaw('min(s_first.stock_date)')
                    ->whereColumn('s_first.item_id', 'stocks.item_id')
                    ->whereColumn('s_first.shop_id', 'stocks.shop_id'),
                'first_seen'
            )
            ->get();
    }

    /**
     * Net sales (sales - refunds; qty and revenue) per shop, item and day, for the pairs with a rule,
     * from the oldest snapshot that matters (bounded by the lookback) up to now.
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<int, array<string, array{0: float, 1: float}>>>
     */
    private function dailySales(array $scope, $pairs, Carbon $today): array
    {
        $floor = $today->copy()->subDays(self::LOOKBACK_DAYS)->toDateString();
        $oldest = max(substr((string) $pairs->min('stock_date'), 0, 10), $floor);
        // Always reach back far enough for the whole history window.
        $oldest = min($oldest, $today->copy()->subDays(self::HISTORY_DAYS)->toDateString());

        $rows = DB::table('receipt_items as ri')
            ->join('receipts', 'receipts.id', '=', 'ri.receipt_id')
            ->join('item_order_rules as r', function ($join) {
                $join->on('r.item_id', '=', 'ri.item_id')->on('r.shop_id', '=', 'receipts.shop_id');
            })
            ->where('r.max', '>', 0)
            ->where('receipts.active', true)
            ->where('ri.storno', false)
            ->where('receipts.created_at', '>=', $oldest.' 00:00:00')
            ->whereIn('receipts.shop_id', $scope)
            ->groupBy('receipts.shop_id', 'ri.item_id', 'd')
            ->selectRaw('receipts.shop_id as shop_id, ri.item_id as item_id, DATE(receipts.created_at) as d, '
                .'SUM(CASE WHEN receipts.sell = 1 THEN ri.qty ELSE -ri.qty END) as net, '
                .'SUM(CASE WHEN receipts.sell = 1 THEN ri.total ELSE -ri.total END) as rev')
            ->get();

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(int) $r->shop_id][(int) $r->item_id][(string) $r->d] = [(float) $r->net, (float) $r->rev];
        }

        return $byDay;
    }

    /**
     * Days inside the history window on which a pair's stock snapshot was <= 0 (out of stock).
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<int, array<string, true>>>
     */
    private function censoredDays(array $scope, Carbon $today): array
    {
        $rows = DB::table('stocks')
            ->join('item_order_rules as r', function ($join) {
                $join->on('r.item_id', '=', 'stocks.item_id')->on('r.shop_id', '=', 'stocks.shop_id');
            })
            ->where('r.max', '>', 0)
            ->where('stocks.qty', '<=', 0)
            ->where('stocks.stock_date', '>=', $today->copy()->subDays(self::HISTORY_DAYS)->toDateString())
            ->where('stocks.stock_date', '<', $today->toDateString())
            ->whereIn('stocks.shop_id', $scope)
            ->get(['stocks.shop_id', 'stocks.item_id', 'stocks.stock_date']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->shop_id][(int) $r->item_id][substr((string) $r->stock_date, 0, 10)] = true;
        }

        return $out;
    }

    /**
     * Quantities on open orders (not yet fulfilled) per shop and item.
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<int, float>>
     */
    private function inTransit(array $scope): array
    {
        $rows = DB::table('order_items as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->whereIn('o.status', OrderStatus::open())
            ->whereIn('o.shop_id', $scope)
            ->groupBy('o.shop_id', 'ol.item_id')
            ->selectRaw('o.shop_id as shop_id, ol.item_id as item_id, SUM(ol.qty) as qty')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->shop_id][(int) $r->item_id] = (float) $r->qty;
        }

        return $out;
    }
}
