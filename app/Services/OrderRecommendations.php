<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Order recommendations per shop for the people preparing deliveries from the
 * main warehouse: RULES + SALES SPEED. (The dashboard's Ordering page keeps
 * the rules-only OrderingReport; this class reuses its estimated-stock logic.)
 *
 * Metric dictionary
 * -----------------
 * estimated stock   current snapshot (Stock::applyCurrent) minus net sales (sales - refunds, active receipts,
 *                   storno lines excluded) from the snapshot DAY on, never below 0 - same as OrderingReport
 * daily speed       net sales of the last WINDOW_DAYS (14) COMPLETE days (today is partial, so it is left out),
 *                   zero-sales days included, divided by the days the item could sell in that shop:
 *                   min(14, days since the first stock snapshot of the pair). Fewer than MIN_SPEED_DAYS (3)
 *                   days of history = no speed (rules only). Negative net (more refunds than sales) = 0.
 *                   Caveat: days the item was out of stock are not excluded (they are not reliably known),
 *                   so the speed of an item that was out of stock for long can be understated.
 * days of cover     estimated stock / daily speed (none when the speed is 0 or unknown)
 * candidate         estimated stock <= min (rule, as OrderingReport)  OR  days of cover < COVER_ALERT_DAYS (3)
 *                   Only items with a rule (max > 0) are covered.
 * target            max; when the item sells, the larger of max and min(speed x COVER_TARGET_DAYS (7),
 *                   2 x max) - the cap stops one busy week from inflating the order beyond a sensible size
 * recommended qty   target - estimated stock, candidates only when > 0. Piece items (min, max and stock all
 *                   whole numbers) are rounded UP to whole pieces; other items (kg, litres) up to 0.1.
 * urgency           out (estimated stock 0) > critical (cover < 1 day) > soon (cover < 3 days) > low
 *                   (at or below min with enough cover or no speed)
 * warehouse stock   current snapshot of the main warehouse shop (Stock::applyCurrent); the main warehouse is
 *                   never replenished itself
 * allocation        per item, when the warehouse has less than the total need: the warehouse quantity (floored
 *                   to a whole piece / 0.1) is given to the most urgent TIER first (out, then critical, soon,
 *                   low) and a tier is served in full while stock lasts; when a tier cannot be served in full
 *                   its demands share the rest proportionally to their need (floored to the unit), left-over
 *                   units go to the lowest days of cover. Rest of the lines = shortage. The allocation only
 *                   considers the shops in view (the viewer's shops for an operator).
 * shortage          need - send > 0 (only when the warehouse is configured)
 *
 * Scale: catalog of thousands of items x up to ~11 shops with rules = tens of thousands of (shop, item)
 * pairs. Four set-based queries (current stock + rule pairs with the first-snapshot date, net sales since
 * snapshot, 14-day net sales grouped by shop+item, warehouse stock); the only PHP loop is one pass over
 * the pairs. The caller caches the result (~60 s).
 */
class OrderRecommendations
{
    public const WINDOW_DAYS = 14;

    public const MIN_SPEED_DAYS = 3;

    public const COVER_ALERT_DAYS = 3;

    public const COVER_TARGET_DAYS = 7;

    public const TARGET_CAP_FACTOR = 2;

    /** Never add up sales further back than this since an old snapshot. */
    public const LOOKBACK_DAYS = 31;

    public const RANK = ['out' => 0, 'critical' => 1, 'soon' => 2, 'low' => 3];

    /**
     * @param  array<int, int>|null  $shopIds  shops to recommend for (null = every shop)
     * @param  int|null  $warehouseId  the main warehouse shop: excluded from the shops, its stock limits the sending
     * @return array<string, mixed>
     */
    public function build(?array $shopIds, ?int $warehouseId, ?Carbon $now = null): array
    {
        $now ??= now();
        $todayStart = $now->copy()->startOfDay();

        $shops = ShopAccess::restrictTo(DB::table('shops'), 'shops.id', $shopIds)
            ->when($warehouseId !== null, fn ($q) => $q->where('shops.id', '!=', $warehouseId))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name']);
        $scope = $shops->pluck('id')->map(fn ($id) => (int) $id)->all();

        $rowsByShop = [];
        if ($scope !== []) {
            $pairs = $this->pairs($scope);
            $since = $this->netSinceSnapshot($scope, $pairs, $now);
            $speedNet = $this->netLastDays($scope, $todayStart);

            foreach ($pairs as $p) {
                $row = $this->recommend($p, $since[$p->shop_id][$p->item_id] ?? [], $speedNet[$p->shop_id][$p->item_id] ?? 0.0, $todayStart);
                if ($row !== null) {
                    $rowsByShop[(int) $p->shop_id][] = $row;
                }
            }
        }

        $configured = $warehouseId !== null;
        $available = $configured && $rowsByShop !== [] ? $this->warehouseStock($warehouseId) : [];

        [$rowsByShop, $pick] = $this->allocate($rowsByShop, $configured, $available);

        $result = [];
        $kpi = ['shops' => 0, 'lines' => 0, 'items' => count($pick), 'out' => 0, 'critical' => 0, 'soon' => 0, 'low' => 0, 'short_items' => 0, 'short_lines' => 0];
        foreach ($shops as $shop) {
            $rows = $rowsByShop[(int) $shop->id] ?? [];
            usort($rows, fn (array $a, array $b) => $this->sortKey($a) <=> $this->sortKey($b));

            $counts = ['out' => 0, 'critical' => 0, 'soon' => 0, 'low' => 0];
            $short = 0;
            foreach ($rows as $r) {
                $counts[$r['urgency']]++;
                $short += $r['short'] > 0 ? 1 : 0;
            }

            foreach ($counts as $k => $n) {
                $kpi[$k] += $n;
            }
            $kpi['lines'] += count($rows);
            $kpi['short_lines'] += $short;
            $kpi['shops'] += $rows === [] ? 0 : 1;

            $result[] = [
                'id' => (int) $shop->id,
                'name' => (string) $shop->name,
                'count' => count($rows),
                'counts' => $counts,
                'short' => $short,
                'rows' => $rows,
            ];
        }

        // Shops with the most urgent needs first; shops with nothing to send go last (alphabetical).
        usort($result, fn (array $a, array $b) => [$a['count'] === 0 ? 1 : 0, -$a['counts']['out'], -$a['counts']['critical'], -$a['counts']['soon'], -$a['count'], $a['name']]
            <=> [$b['count'] === 0 ? 1 : 0, -$b['counts']['out'], -$b['counts']['critical'], -$b['counts']['soon'], -$b['count'], $b['name']]);

        $kpi['short_items'] = count(array_filter($pick, fn (array $i) => $i['short'] > 0));

        return [
            'generated_at' => $now->format('H:i:s'),
            'warehouse_configured' => $configured,
            'kpi' => $kpi,
            'shops' => $result,
            'pick' => $pick,
        ];
    }

    /**
     * Splits a scarce quantity over demands (see class doc, "allocation"). Pure function, used per item.
     *
     * @param  array<int, array{key: mixed, rank: int, cover: ?float, need: float}>  $demands
     * @return array<int|string, float> key => quantity to send (0 when nothing is left)
     */
    public static function splitScarce(float $available, array $demands, float $unit): array
    {
        $left = self::floorTo(max($available, 0.0), $unit);
        $send = [];
        foreach ($demands as $d) {
            $send[$d['key']] = 0.0;
        }

        usort($demands, fn ($a, $b) => [$a['rank'], $a['cover'] ?? INF, $a['key']] <=> [$b['rank'], $b['cover'] ?? INF, $b['key']]);

        foreach (array_unique(array_column($demands, 'rank')) as $rank) {
            $tier = array_values(array_filter($demands, fn ($d) => $d['rank'] === $rank));
            $need = array_sum(array_column($tier, 'need'));
            if ($left <= 0) {
                break;
            }

            if ($need <= $left + 1e-9) {
                foreach ($tier as $d) {
                    $send[$d['key']] = (float) $d['need'];
                }
                $left -= $need;

                continue;
            }

            $given = 0.0;
            foreach ($tier as $d) {
                $part = min(self::floorTo($left * $d['need'] / $need, $unit), $d['need']);
                $send[$d['key']] = $part;
                $given += $part;
            }
            $rest = round($left - $given, 6);
            foreach ($tier as $d) {
                if ($rest + 1e-9 < $unit) {
                    break;
                }
                $room = $d['need'] - $send[$d['key']];
                $add = min($rest, $room);
                $add = self::floorTo($add, $unit);
                if ($add > 0) {
                    $send[$d['key']] += $add;
                    $rest = round($rest - $add, 6);
                }
            }
            $left = 0.0;
        }

        return $send;
    }

    private static function floorTo(float $value, float $unit): float
    {
        return round(floor($value / $unit + 1e-9) * $unit, 6);
    }

    /**
     * One recommendation row for a (shop, item) pair, or null when nothing needs to be sent.
     *
     * @param  array<string, float>  $since  net sales per day (Y-m-d) since the oldest relevant snapshot
     */
    private function recommend(object $p, array $since, float $net14, Carbon $todayStart): ?array
    {
        $stock = (float) $p->qty;
        $min = (float) $p->min;
        $max = (float) $p->max;

        $net = 0.0;
        foreach ($since as $day => $value) {
            if ($day >= $p->stock_date) {
                $net += $value;
            }
        }
        $estimated = max($stock - $net, 0.0);

        $days = $p->first_seen === null ? 0 : min(self::WINDOW_DAYS, max(0, (int) Carbon::parse($p->first_seen)->startOfDay()->diffInDays($todayStart, false)));
        $speed = $days >= self::MIN_SPEED_DAYS ? max($net14, 0.0) / $days : null;
        $cover = $speed !== null && $speed > 0 ? $estimated / $speed : null;

        $belowMin = $estimated <= $min;
        $coverLow = $cover !== null && $cover < self::COVER_ALERT_DAYS;
        if (! $belowMin && ! $coverLow) {
            return null;
        }

        $target = $max;
        if ($speed !== null && $speed > 0) {
            $target = max($max, min($speed * self::COVER_TARGET_DAYS, $max * self::TARGET_CAP_FACTOR));
        }
        $need = $target - $estimated;
        if ($need <= 1e-9) {
            return null;
        }

        $piece = $this->isWhole($stock) && $this->isWhole($min) && $this->isWhole($max);
        $need = $piece ? ceil($need - 1e-9) : ceil($need * 10 - 1e-9) / 10;

        $urgency = $estimated <= 0 ? 'out'
            : ($cover !== null && $cover < 1 ? 'critical'
            : ($cover !== null && $cover < self::COVER_ALERT_DAYS ? 'soon' : 'low'));

        return [
            'item_id' => (int) $p->item_id,
            'item' => (string) $p->item_name,
            'shop_id' => (int) $p->shop_id,
            'stock' => $stock,
            'estimated' => round($estimated, 3),
            'min' => $min,
            'max' => $max,
            'speed' => $speed === null ? null : round($speed, 3),
            'cover' => $cover === null ? null : round($cover, 1),
            'reason' => $belowMin && $coverLow ? 'both' : ($belowMin ? 'min' : 'cover'),
            'urgency' => $urgency,
            'need' => (float) $need,
            'send' => (float) $need,
            'short' => 0.0,
            'piece' => $piece,
        ];
    }

    private function isWhole(float $v): bool
    {
        return abs($v - round($v)) < 1e-9;
    }

    /** @return array<int, mixed> */
    private function sortKey(array $r): array
    {
        return [self::RANK[$r['urgency']], $r['cover'] ?? INF, -$r['need'], $r['item']];
    }

    /**
     * Allocation of the warehouse stock and the consolidated pick list (one entry per item).
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rowsByShop
     * @param  array<int, float>  $available  warehouse qty per item id
     * @return array{0: array<int, array<int, array<string, mixed>>>, 1: array<int, array<string, mixed>>}
     */
    private function allocate(array $rowsByShop, bool $configured, array $available): array
    {
        $byItem = [];
        foreach ($rowsByShop as $shopId => $rows) {
            foreach ($rows as $i => $row) {
                $byItem[$row['item_id']][] = [$shopId, $i];
            }
        }

        $shopNames = DB::table('shops')->whereIn('id', array_keys($rowsByShop))->pluck('name', 'id');

        $pick = [];
        foreach ($byItem as $itemId => $refs) {
            $demands = [];
            $piece = true;
            foreach ($refs as $n => [$shopId, $i]) {
                $r = $rowsByShop[$shopId][$i];
                $piece = $piece && $r['piece'];
                $demands[] = ['key' => $n, 'rank' => self::RANK[$r['urgency']], 'cover' => $r['cover'], 'need' => $r['need']];
            }
            $needTotal = array_sum(array_column($demands, 'need'));
            $have = $configured ? (float) ($available[$itemId] ?? 0.0) : null;

            $send = $have === null || $needTotal <= $have + 1e-9
                ? array_column($demands, 'need', 'key')
                : self::splitScarce($have, $demands, $piece ? 1.0 : 0.1);

            $shopsOut = [];
            $worst = 3;
            $minCover = null;
            $sendTotal = 0.0;
            foreach ($refs as $n => [$shopId, $i]) {
                $r = &$rowsByShop[$shopId][$i];
                $r['send'] = (float) $send[$n];
                $r['short'] = $configured ? round($r['need'] - $r['send'], 6) : 0.0;
                $worst = min($worst, self::RANK[$r['urgency']]);
                $minCover = $r['cover'] === null ? $minCover : ($minCover === null ? $r['cover'] : min($minCover, $r['cover']));
                $sendTotal += $r['send'];
                $shopsOut[] = [
                    'shop_id' => $shopId,
                    'shop' => (string) ($shopNames[$shopId] ?? '#'.$shopId),
                    'need' => $r['need'],
                    'send' => $r['send'],
                    'urgency' => $r['urgency'],
                ];
                $name = $r['item'];
                unset($r);
            }
            usort($shopsOut, fn ($a, $b) => [self::RANK[$a['urgency']], -$a['need'], $a['shop']] <=> [self::RANK[$b['urgency']], -$b['need'], $b['shop']]);

            $pick[] = [
                'item_id' => (int) $itemId,
                'item' => (string) $name,
                'need' => round($needTotal, 6),
                'send' => round($sendTotal, 6),
                'available' => $have,
                'short' => $configured ? round($needTotal - $sendTotal, 6) : 0.0,
                'urgency' => array_search($worst, self::RANK, true),
                'cover' => $minCover,
                'shops' => $shopsOut,
            ];
        }

        // What the team prepares first: most urgent, then the lowest cover, then the biggest quantity.
        usort($pick, fn ($a, $b) => [self::RANK[$a['urgency']], $a['cover'] ?? INF, -$a['need'], $a['item']] <=> [self::RANK[$b['urgency']], $b['cover'] ?? INF, -$b['need'], $b['item']]);

        return [$rowsByShop, $pick];
    }

    /** @return array<int, float> current quantity of the main warehouse per item id */
    private function warehouseStock(int $warehouseId): array
    {
        return Stock::applyCurrent(DB::table('stocks'))
            ->where('stocks.shop_id', $warehouseId)
            ->pluck('stocks.qty', 'stocks.item_id')
            ->map(fn ($q) => (float) $q)
            ->all();
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
            ->whereIn('stocks.shop_id', $scope)
            ->where('r.max', '>', 0)
            ->select([
                'stocks.shop_id', 'stocks.item_id', 'stocks.qty', 'stocks.stock_date',
                'r.min', 'r.max', 'items.name as item_name',
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
     * Net sales per shop, item and day from the oldest snapshot that matters up to now (bounded).
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<int, array<string, float>>>
     */
    private function netSinceSnapshot(array $scope, $pairs, Carbon $now): array
    {
        if ($pairs->isEmpty()) {
            return [];
        }

        $floor = $now->copy()->subDays(self::LOOKBACK_DAYS)->startOfDay()->toDateString();
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

    /**
     * Net sales of the WINDOW_DAYS complete days before today, per shop and item (no day dimension).
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<int, float>>
     */
    private function netLastDays(array $scope, Carbon $todayStart): array
    {
        $rows = DB::table('receipt_items as ri')
            ->join('receipts', 'receipts.id', '=', 'ri.receipt_id')
            ->where('receipts.active', true)
            ->where('ri.storno', false)
            ->where('receipts.created_at', '>=', $todayStart->copy()->subDays(self::WINDOW_DAYS))
            ->where('receipts.created_at', '<', $todayStart)
            ->whereIn('receipts.shop_id', $scope)
            ->groupBy('receipts.shop_id', 'ri.item_id')
            ->selectRaw('receipts.shop_id as shop_id, ri.item_id as item_id, SUM(CASE WHEN receipts.sell = 1 THEN ri.qty ELSE -ri.qty END) as net')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->shop_id][(int) $r->item_id] = (float) $r->net;
        }

        return $out;
    }
}
