<?php

namespace App\Services;

use App\Models\OrderRule;
use App\Models\Stock;
use App\Support\InTransit;
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
 * no_data           EXPLICIT ZERO (inventory.explicit_zero_stock, default; see App\Models\Stock): 1C reports zero itself, so a pair
 *                   whose current row is older than its shop's latest sync date R (or that never had a row) is "not reported
 *                   by 1C": unknown stock, NOT out of stock. Such pairs are never listed or planned; build() returns them as
 *                   result['no_data'] = {count, by_shop, items (first 200: shop_id, item_id, item, last_date)}. Rows carry
 *                   `no_data` (false for every returned row) and `stale` (= no_data OR the whole sync older than the 31-day
 *                   lookback; kept so consumers keep working). Flag off: legacy, `stale` = row older than the lookback only.
 * in_transit        quantity on open orders (pending_approval / approved / confirmed, not received) of that shop and item,
 *                   only while the order is at most inventory.in_transit_max_days old (App\Support\InTransit):
 *                   goods that are on their way. position = est + in_transit; cover, stock-out, level, listing
 *                   and the order quantity all work on the position, so an item that was just ordered is not
 *                   suggested again.
 * history           the N COMPLETE days before today (N = the shop's order rule: 14, 21 or 28, default 28), starting at
 *                   max(today - N, the EARLIER of the pair's first sale and its first stock snapshot). Days without sales count as 0. A day is censored
 *                   (out of stock) when the stock snapshot of that date exists and is <= 0: censored days are
 *                   left out of every mean. Daily values are floored at 0 (refund-heavy days) and clipped at
 *                   median + 3 x MAD per pair (flag `clipped`; the MAD is floored at 10 % of the median, and
 *                   nothing is clipped for intermittent items whose median is 0). clip_mode 'weekday' (default)
 *                   lifts the cap of a day to max(median + k x MAD, 2 x the mean of the OTHER days with the same
 *                   weekday), so genuine weekend / pay-day peaks survive; `spike` = a day was clipped to <= 1/3 of its
 *                   value (typo / bulk one-off, worth a look). 'legacy' = median + k x MAD only.
 * refunds           inventory.refund_netting 'same_day' (default): the DAILY DEMAND is sales minus refunds of the SAME day only,
 *                   clamped at 0 per day (abs(qty) on refund lines); a refund of goods sold on an earlier day does not
 *                   lower the day it arrives. The same clamped series feeds the stock estimate (a refund-only day does not raise est) and the ABC revenue. 'legacy' = old netting.
 * silent_days       shop-days whose receipt feed was silent (sale receipts < 20 % of the usual weekday median, usual >= 20
 *                   receipts, median of the other same-weekday days of 28 days, >= 3 of them) are MISSING data, left out of
 *                   every mean like stock-out days; > 25 % of the window silent = confidence one step lower (not below low).
 *                   inventory.feed_aware_censoring=false restores the old behaviour.
 * speed (flat)      the LEVEL: mean daily net sales over the non-censored days of the last 7 complete days; when fewer than
 *                   3 usable days, the last 14, then the last N (the ladder [7, 14, N] is cut at N). Fewer than 3
 *                   usable days in N = no speed.
 * weekday forecast  weekday_shape 'window' (default): f = speed x shape(w), shape(w) = (mean of the usable days with weekday w
 *                   / mean of all usable days of the N-day window) x L + (1 - L), L = n_w / (n_w + 2); floor 0.
 *                   'legacy': f = wd_mean[w] x L + speed x (1 - L) with wd_mean over the speed window only.
 * f[1..7], F[1..7]  f[d] = forecast of the day d days from today (1 = tomorrow). F[H] = today_rem + f[1] + .. + f[H],
 *                   today_rem = max(f(today) - net sold today so far, 0) (the rest of today's demand).
 * sigma             daily demand standard deviation: sample SD of the usable (clipped) days around their expectation
 *                   (window mean x weekday shape), so a regular weekend peak is not counted as noise.
 * ss[1..7]          safety stock = z x sigma x sqrt(H + L): z per ABC class (inventory.service_z: A 1.65, B 1.28, C 0.84),
 *                   L = inventory.lead_time_days (0 = same-day delivery). null when not applied (see below).
 * target[1..7]      stock wanted after the order: max(min, ss) + F(H + L), lifted by safety stock at most to
 *                   max x inventory.max_overshoot (never below the rule target min + F(H)); only for confidence
 *                   ok / high with inventory.safety_stock on. Otherwise min + F(H); rules-only items: max.
 * order[1..7]       max(0, ceil(target[H] - position)): whole pieces when stock, min and max are whole, else up to
 *                   0.1. Never decreases with H.
 * cover_days        days until the position is used up when walking the weekday forecast (rest of today as a fraction
 *                   of a day, then f[1], f[2] ..); legacy shape: position / speed. null without speed or with speed 0.
 * stockout_in/_date first d (0 = today, up to 7) where F[d] exceeds the position; today when position <= 0; else null
 * confidence        none (< 3 usable days: rules only) | low (3-9) | ok (>= 10) | high (>= 14 and every weekday >= 2)
 * level             out (position <= 0) | critical (cover < 1) | soon (cover < 3) | low (at/below min or cover < 7)
 *                   | over (above max or cover > 14) | ok
 * abc               per shop, items by 28-day net revenue (always 28 days, whatever the history window): A while the cumulative share is <= 80 %, B <= 95 %, else C
 * xyz               coefficient of variation of the daily demand: X < 0.5, Y < 1, else Z (null without speed)
 * spike, sigma     see above (spike: boolean flag, sigma: daily SD used for the safety stock)
 * Future work (not implemented): same-day snapshot sync time, pack / case rounding, Croston for intermittent items,
 * imputing censored (out-of-stock) days.
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
            'no_data' => ['count' => 0, 'by_shop' => [], 'items' => []],
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

        $noData = ['count' => 0, 'by_shop' => [], 'items' => []];
        foreach ($all as $row) {
            $row = $this->finalize($row);
            unset($row['revenue']);
            if ($row['no_data']) {
                // Not reported by 1C: unknown stock, never listed, never planned (surfaced as a count and a short list).
                $this->noteNoData($noData, $row['shop_id'], $row['item_id'], $row['item'], $row['stock_date']);

                continue;
            }
            if ($row['listed'][self::MAX_HORIZON]) {
                $result['rows'][] = $row;
            }
        }
        if (Stock::explicitZero()) {
            $this->neverReported($scope, $noData);
        }
        $result['no_data'] = $noData;

        return $result;
    }

    public const NO_DATA_LIST_CAP = 200;

    /** @param  array{count: int, by_shop: array<int, int>, items: array<int, array<string, mixed>>}  $noData */
    private function noteNoData(array &$noData, int $shopId, int $itemId, string $item, ?string $lastDate): void
    {
        $noData['count']++;
        $noData['by_shop'][$shopId] = ($noData['by_shop'][$shopId] ?? 0) + 1;
        if (count($noData['items']) < self::NO_DATA_LIST_CAP) {
            $noData['items'][] = ['shop_id' => $shopId, 'item_id' => $itemId, 'item' => $item, 'last_date' => $lastDate];
        }
    }

    /**
     * Rule pairs (max > 0) of the shops that never had a stock row at all: also "not reported by 1C".
     *
     * @param  array<int, int>  $scope
     * @param  array{count: int, by_shop: array<int, int>, items: array<int, array<string, mixed>>}  $noData
     */
    private function neverReported(array $scope, array &$noData): void
    {
        $rows = DB::table('item_order_rules as r')
            ->join('items', 'items.id', '=', 'r.item_id')
            ->whereIn('r.shop_id', $scope)->where('r.max', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('stocks as s_any')
                    ->whereColumn('s_any.item_id', 'r.item_id')->whereColumn('s_any.shop_id', 'r.shop_id');
            })
            ->get(['r.shop_id', 'r.item_id', 'items.name']);
        foreach ($rows as $r) {
            $this->noteNoData($noData, (int) $r->shop_id, (int) $r->item_id, (string) $r->name, null);
        }
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
        $silent = config('inventory.feed_aware_censoring', true) ? $this->silentShopDays($scope, $today) : [];
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
            // R per shop (explicit zero): a pair whose current row is older than its shop's latest sync is not reported.
            'latest' => Stock::explicitZero() ? Stock::latestDates($scope) : [],
            'factor' => (float) config('inventory.outlier_mad_factor', 3),
            'clipMode' => config('inventory.clip_mode', 'weekday') === 'legacy' ? 'legacy' : 'weekday',
            'shapeMode' => config('inventory.weekday_shape', 'window') === 'legacy' ? 'legacy' : 'window',
            'lead' => max(0, min(self::MAX_HORIZON, (int) config('inventory.lead_time_days', 0))),
        ];

        $rows = [];
        foreach ($pairs as $p) {
            $rows[] = $this->pair(
                $p,
                $sales[$p->shop_id][$p->item_id] ?? [],
                $censored[$p->shop_id][$p->item_id] ?? [],
                $transit[$p->shop_id][$p->item_id] ?? 0.0,
                $ctx,
                $silent[$p->shop_id] ?? [],
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, array{0: float, 1: float}>  $sales  [net qty, net revenue] per day
     * @param  array<string, true>  $censoredDays
     * @param  array<string, true>  $silentDays  shop-days whose receipt feed was silent (missing data, not zero demand)
     * @return array<string, mixed>
     */
    private function pair(object $p, array $sales, array $censoredDays, float $transit, array $ctx, array $silentDays = []): array
    {
        $stock = (float) $p->qty;
        $stockDate = substr((string) $p->stock_date, 0, 10);
        $noData = Stock::explicitZero() && ! Stock::isReported($stockDate, $ctx['latest'][$p->shop_id] ?? null);
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
        $silentCount = 0;
        $spanDays = 0;
        foreach ($ctx['history'] as $day => $meta) {
            if ($day < $start) {
                continue;
            }
            $spanDays++;
            if (isset($censoredDays[$day])) {
                $censoredCount++;

                continue;
            }
            if (isset($silentDays[$day])) {
                // The shop's feed was silent: unknown demand, left out of every mean like a stock-out day.
                $silentCount++;

                continue;
            }
            $obs[] = [$meta['idx'], $meta['wd'], max($sales[$day][0] ?? 0.0, 0.0)];
        }

        $usable = count($obs);
        $clipped = false;
        $spike = false;
        if ($usable >= self::MIN_USABLE_DAYS) {
            $values = array_column($obs, 2);
            $median = $this->median($values);
            $mad = $this->median(array_map(fn ($v) => abs($v - $median), $values));
            // MAD = 0 (steady demand with a few deviations): the scale is floored at 10 % of the median, but
            // nothing is clipped for intermittent items (median 0), whose sporadic sales are real demand.
            $scale = max($mad, 0.1 * $median);
            if ($scale > 0) {
                $base = $median + $ctx['factor'] * $scale;
                $byWd = [];
                foreach ($obs as $i => $o) {
                    $byWd[$o[1]][$i] = $values[$i];
                }
                foreach ($obs as $i => &$o) {
                    $cap = $base;
                    if ($ctx['clipMode'] === 'weekday') {
                        // Same-weekday baseline: 2 x the mean of the OTHER days with this weekday (the day itself is
                        // left out so a typo cannot raise its own cap). A weekday seen once keeps the base cap.
                        $others = array_diff_key($byWd[$o[1]], [$i => true]);
                        if ($others !== []) {
                            $cap = max($cap, 2 * (array_sum($others) / count($others)));
                        }
                    }
                    if ($o[2] > $cap) {
                        if ($o[2] >= 3 * $cap) {
                            $spike = true;
                        }
                        $o[2] = $cap;
                        $clipped = true;
                    }
                }
                unset($o);
            }
        }

        // Weekday statistics over ALL usable days of the history window (after clipping).
        $allSum = array_fill(0, 7, 0.0);
        $allCnt = array_fill(0, 7, 0);
        foreach ($obs as [, $wd, $v]) {
            $allSum[$wd] += $v;
            $allCnt[$wd]++;
        }
        $allMean = $usable > 0 ? array_sum($allSum) / $usable : 0.0;
        $shape = function (int $wd) use ($allSum, $allCnt, $allMean): float {
            $n = $allCnt[$wd];
            if ($n === 0 || $allMean <= 0) {
                return 1.0;
            }
            $lambda = $n / ($n + 2);

            return ($allSum[$wd] / $n / $allMean) * $lambda + (1 - $lambda);
        };
        $legacyShape = $ctx['shapeMode'] === 'legacy';

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
        // More than a quarter of the window had no feed: one confidence step down (never below low while a speed exists).
        if ($silentCount > 0 && $spanDays > 0 && $silentCount / $spanDays > 0.25) {
            $confidence = $confidence === 'high' ? 'ok' : ($confidence === 'ok' ? 'low' : $confidence);
        }

        $lead = $ctx['lead'];
        $f = [];
        $cum = [];
        $cumFull = [];   // cumulative forecast up to MAX_HORIZON + lead time (safety-stock horizon H + L)
        $todayRem = 0.0;
        $sigma = null;
        $forecast = null;
        if ($flat !== null) {
            $forecast = $legacyShape
                ? function (int $wd) use ($flat, $wdSum, $wdCnt): float {
                    $n = $wdCnt[$wd];
                    $lambda = $n / ($n + 2);
                    $mean = $n > 0 ? $wdSum[$wd] / $n : 0.0;

                    return max($mean * $lambda + $flat * (1 - $lambda), 0.0);
                }
            : fn (int $wd): float => max($flat * $shape($wd), 0.0);
            $todayRem = max($forecast($ctx['todayWd']) - $soldToday, 0.0);
            $running = $todayRem;
            for ($d = 1; $d <= self::MAX_HORIZON + $lead; $d++) {
                $fd = $forecast(($ctx['todayWd'] + $d) % 7);
                $running += $fd;
                $cumFull[$d] = $running;
                if ($d <= self::MAX_HORIZON) {
                    $f[$d] = $fd;
                    $cum[$d] = $running;
                }
            }

            // sigma_daily: sample standard deviation of the usable (clipped) days around their weekday
            // expectation (window mean x shrunk weekday shape), so a normal weekend peak is not "noise".
            $ss = 0.0;
            foreach ($obs as [, $wd, $v]) {
                $ss += ($v - $allMean * $shape($wd)) ** 2;
            }
            $sigma = sqrt($ss / max($usable - 1, 1));
        } else {
            for ($d = 1; $d <= self::MAX_HORIZON; $d++) {
                $f[$d] = 0.0;
                $cum[$d] = 0.0;
            }
        }

        $piece = $this->isWhole($stock) && $this->isWhole($min) && $this->isWhole($max);

        // cover_days: legacy = position / flat speed; otherwise walk the weekday forecast like stockout_in.
        $cover = null;
        if ($flat !== null && $flat > 0) {
            $cover = $legacyShape ? $position / $flat : $this->walkCover($position, $todayRem, $f, $forecast, $ctx['todayWd']);
        }

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
            'silent_days' => $silentCount,
            'clipped' => $clipped,
            'spike' => $spike,
            'sigma' => $sigma === null ? null : round($sigma, 3),
            'no_data' => $noData,
            'stale' => $noData || $stockDate < $ctx['staleBefore'],
            'abc' => 'C',
            'xyz' => $xyz,
            'cover_days' => $cover === null ? null : round($cover, 1),
            'stockout_in' => $stockoutIn,
            'stockout_date' => $stockoutIn === null ? null : Carbon::parse($ctx['today'])->addDays($stockoutIn)->toDateString(),
            'level' => $level,
            'today_rem' => round($todayRem, 3),
            'f' => array_map(fn ($v) => round($v, 3), $f),
            'F' => array_map(fn ($v) => round($v, 3), $cum),
            'order' => [],
            'ss' => null,
            'target' => [],
            'listed' => [],
            'revenue' => $revenue,
            '_c' => ['cum' => $cum, 'cumFull' => $cumFull, 'sigma' => $sigma, 'position' => $position, 'lead' => $lead],
        ];
    }

    /**
     * Order quantity, safety stock and listing per horizon; runs after the ABC class is known (z depends on it).
     *
     * target[H] = min + F(H) (rules) or, with safety stock on and confidence ok / high,
     * max(min, SS) + F(H + L) with SS = z x sigma x sqrt(H + L), lifted at most to max x max_overshoot.
     * order[H] = ceil(target[H] - position).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finalize(array $row): array
    {
        $c = $row['_c'];
        unset($row['_c']);

        $min = $row['min'];
        $max = $row['max'];
        $position = $c['position'];
        $flat = $row['speed'] === null ? null : true;
        $useSs = $flat !== null && config('inventory.safety_stock', true)
            && in_array($row['confidence'], ['ok', 'high'], true) && $c['sigma'] !== null;

        $zMap = (array) config('inventory.service_z', []);
        $z = (float) ($zMap[$row['abc']] ?? $zMap['default'] ?? 0.84);
        $overshoot = (float) config('inventory.max_overshoot', 1.5);

        $order = [];
        $ss = [];
        $target = [];
        for ($h = 1; $h <= self::MAX_HORIZON; $h++) {
            if ($flat === null) {
                $t = $max;
            } else {
                $t = $min + $c['cum'][$h];
                if ($useSs) {
                    $s = $z * $c['sigma'] * sqrt($h + $c['lead']);
                    $new = max($min, $s) + $c['cumFull'][$h + $c['lead']];
                    if ($overshoot > 0) {
                        $new = min($new, $max * $overshoot);
                    }
                    $t = max($t, $new);
                    $ss[$h] = round($s, 3);
                }
            }
            $target[$h] = round($t, 3);
            $order[$h] = $this->roundUp($t - $position, $row['piece']);
        }

        $listed = [];
        for ($h = 1; $h <= self::MAX_HORIZON; $h++) {
            $listed[$h] = ($position <= $min || ($row['cover_days'] !== null && $row['cover_days'] < $h)
                || ($row['stockout_in'] !== null && $row['stockout_in'] <= $h))
                && $order[$h] > 0;
        }

        $row['order'] = $order;
        $row['ss'] = $useSs ? $ss : null;
        $row['target'] = $target;
        $row['listed'] = $listed;

        return $row;
    }

    /**
     * Days until the position is used up, walking the weekday forecast: the rest of today counts as the
     * fraction todayRem / f(today) of a day, then f[1], f[2] .. (7 days known, then the same weekday pattern
     * repeats up to 28 days; beyond that the average daily forecast of the week is used).
     *
     * @param  array<int, float>  $f
     */
    private function walkCover(float $position, float $todayRem, array $f, \Closure $forecast, int $todayWd): ?float
    {
        $remaining = $position;
        $t = 0.0;
        $fToday = $forecast($todayWd);
        if ($todayRem > 0) {
            if ($remaining <= $todayRem) {
                return $fToday > 0 ? $remaining / $todayRem * ($todayRem / $fToday) : 0.0;
            }
            $remaining -= $todayRem;
            $t = $fToday > 0 ? $todayRem / $fToday : 0.0;
        }
        for ($d = 1; $d <= 28; $d++) {
            $fd = $d <= self::MAX_HORIZON ? $f[$d] : $forecast(($todayWd + $d) % 7);
            if ($fd > 0 && $remaining <= $fd) {
                return $t + $remaining / $fd;
            }
            $remaining -= $fd;
            $t += 1;
        }
        $week = 0.0;
        for ($wd = 0; $wd < 7; $wd++) {
            $week += $forecast($wd);
        }

        return $week > 0 ? $t + $remaining / ($week / 7) : null;
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
                .'SUM(CASE WHEN receipts.sell = 1 THEN ri.total ELSE -ri.total END) as rev, '
                .'SUM(CASE WHEN receipts.sell = 1 THEN ri.qty ELSE 0 END) as sold_qty, '
                .'SUM(CASE WHEN receipts.sell = 1 THEN 0 ELSE ABS(ri.qty) END) as ret_qty, '
                .'SUM(CASE WHEN receipts.sell = 1 THEN ri.total ELSE 0 END) as sold_rev, '
                .'SUM(CASE WHEN receipts.sell = 1 THEN 0 ELSE ABS(ri.total) END) as ret_rev')
            ->get();

        $legacy = config('inventory.refund_netting', 'same_day') === 'legacy';

        $byDay = [];
        foreach ($rows as $r) {
            if ($legacy) {
                $byDay[(int) $r->shop_id][(int) $r->item_id][(string) $r->d] = [(float) $r->net, (float) $r->rev];

                continue;
            }
            // same_day: a refund is netted only against the sales of its own day and a day never goes below 0, so a
            // refund of goods sold earlier cannot push this day, the stock estimate or the ABC revenue below 0.
            $byDay[(int) $r->shop_id][(int) $r->item_id][(string) $r->d] = [
                max((float) $r->sold_qty - (float) $r->ret_qty, 0.0),
                max((float) $r->sold_rev - (float) $r->ret_rev, 0.0),
            ];
        }

        return $byDay;
    }

    /**
     * Shop-days (inside the history window) on which the shop's receipt feed was silent: the day's sale receipts are
     * below inventory.feed_silence_min_ratio x the usual weekday median (the other same-weekday days of the last 28
     * days, needs inventory.feed_silence_min_weekdays of them) AND the usual median is at least
     * inventory.feed_silence_min_median receipts. One grouped query for all shops.
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<string, true>> shop id => date => true
     */
    private function silentShopDays(array $scope, Carbon $today): array
    {
        $from = $today->copy()->subDays(self::HISTORY_DAYS);
        $rows = DB::table('receipts')
            ->where('active', true)->where('sell', true)
            ->where('created_at', '>=', $from->toDateString().' 00:00:00')
            ->where('created_at', '<', $today->toDateString().' 00:00:00')
            ->whereIn('shop_id', $scope)
            ->groupBy('shop_id', 'd')
            ->selectRaw('shop_id, DATE(created_at) as d, COUNT(*) as n')
            ->get();

        $count = [];
        foreach ($rows as $r) {
            $count[(int) $r->shop_id][(string) $r->d] = (int) $r->n;
        }

        $ratio = (float) config('inventory.feed_silence_min_ratio', 0.2);
        $minMedian = (int) config('inventory.feed_silence_min_median', 20);
        $minDays = max(1, (int) config('inventory.feed_silence_min_weekdays', 3));

        $out = [];
        foreach ($scope as $shopId) {
            if (empty($count[$shopId])) {
                continue;   // no receipts at all in 28 days: nothing to compare with
            }
            $byWd = [];
            $days = [];
            for ($i = 1; $i <= self::HISTORY_DAYS; $i++) {
                $day = $today->copy()->subDays($i);
                $date = $day->toDateString();
                $days[$date] = $day->dayOfWeek;
                $byWd[$day->dayOfWeek][$date] = $count[$shopId][$date] ?? 0;
            }
            foreach ($days as $date => $wd) {
                $others = array_values(array_diff_key($byWd[$wd], [$date => true]));
                if (count($others) < $minDays) {
                    continue;
                }
                $median = $this->median(array_map('floatval', $others));
                if ($median >= $minMedian && ($count[$shopId][$date] ?? 0) < $ratio * $median) {
                    $out[$shopId][$date] = true;
                }
            }
        }

        return $out;
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
     * Quantities on open orders per shop and item: confirmed, not received and confirmed within
     * inventory.in_transit_max_days (see App\Support\InTransit).
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<int, float>>
     */
    private function inTransit(array $scope): array
    {
        $rows = InTransit::apply(DB::table('order_items as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id'))
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
