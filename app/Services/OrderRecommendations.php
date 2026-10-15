<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderRule;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Order lines per shop and the consolidated warehouse pick list for a horizon of H days, on top of
 * ForecastService (the demand model: est, in-transit, weekday forecast, order[H], see its dictionary).
 * Used by the Ordering page (any H, 1-7) and the warehouse monitor (H = inventory.order_horizon_days).
 *
 * Metric dictionary (what this class adds)
 * ----------------------------------------
 * line              a forecast row listed for H (position <= min OR cover < H OR stock-out within H days,
 *                   and order[H] > 0); need = order[H]
 * urgency           the forecast level: out > critical (cover < 1 day) > soon (< 3 days) > low
 * tier              allocation priority of a line: 0 out of stock, 1 stock-out within 1 day, 2 stock-out/cover
 *                   within H days, 3 the rest (at/below min only)
 * warehouse stock   Stock::applyCurrent of the main warehouse shop MINUS the quantities on open orders
 *                   (pending_approval / approved, any shop; not fulfilled), never below 0. The warehouse is
 *                   never replenished itself.
 * allocation        per item, when the warehouse has less than the total need: the quantity (floored to a
 *                   whole piece / 0.1) goes to the most urgent TIER first (served in full), the tier that
 *                   cannot be served in full shares the rest proportionally (floored to the unit, left-over
 *                   units to the lowest cover). Only the shops in view take part (the viewer's shops for an operator).
 * send / short      send = allocated quantity, short = need - send (only when the warehouse is configured);
 *                   starved = an out-of-stock line that receives nothing
 *
 * No warehouse configured: needs only (send = need, no shortage check).
 */
class OrderRecommendations
{
    public const RANK = ['out' => 0, 'critical' => 1, 'soon' => 2, 'low' => 3];

    /**
     * Forecast + plan in one go (the warehouse monitor).
     *
     * @param  array<int, int>|null  $shopIds  shops to recommend for (null = every shop)
     * @param  int|null  $warehouseId  the main warehouse shop: excluded from the shops, its stock limits the sending
     * @param  int|null  $horizon  one horizon for every shop; null = each shop's own order rule
     * @return array<string, mixed>
     */
    public function build(?array $shopIds, ?int $warehouseId, ?Carbon $now = null, ?int $horizon = null): array
    {
        $forecast = app(ForecastService::class)->build($shopIds, $warehouseId === null ? [] : [$warehouseId], $now);

        return $this->plan($forecast, $horizon === null ? null : ForecastService::horizon($horizon), $warehouseId);
    }

    /**
     * Lines, shop summaries and the pick list for horizon $horizon from an (optionally cached) forecast.
     * Cheap: two small stock/order queries and one pass over the listed lines.
     *
     * @param  array<string, mixed>  $forecast  ForecastService::build() result
     * @param  int|null  $horizon  one horizon (1-7) for every shop; null = each shop's own order rule (shop entries
     *                             and lines carry their horizon, `horizon` of the result is the chain default)
     * @return array<string, mixed>
     */
    public function plan(array $forecast, ?int $horizon, ?int $warehouseId): array
    {
        $default = ForecastService::horizon($horizon);
        $horizons = $horizon === null
            ? OrderRule::horizons(array_column($forecast['shops'], 'id'))
            : [];
        $horizonOf = fn (int $shopId): int => $horizons[$shopId] ?? $default;

        $rowsByShop = [];
        foreach ($forecast['rows'] as $r) {
            $h = $horizonOf($r['shop_id']);
            if ($r['listed'][$h]) {
                $rowsByShop[$r['shop_id']][] = $this->line($r, $h);
            }
        }

        $configured = $warehouseId !== null;
        $available = $configured && $rowsByShop !== [] ? $this->warehouseStock($warehouseId) : [];
        $shopNames = collect($forecast['shops'])->pluck('name', 'id')->all();

        [$rowsByShop, $pick] = $this->allocate($rowsByShop, $configured, $available, $shopNames);

        $result = [];
        $kpi = ['shops' => 0, 'lines' => 0, 'items' => count($pick), 'out' => 0, 'critical' => 0, 'soon' => 0, 'low' => 0, 'short_items' => 0, 'short_lines' => 0, 'starved' => 0];
        foreach ($forecast['shops'] as $shop) {
            $rows = $rowsByShop[$shop['id']] ?? [];
            usort($rows, fn (array $a, array $b) => $this->sortKey($a) <=> $this->sortKey($b));

            $counts = ['out' => 0, 'critical' => 0, 'soon' => 0, 'low' => 0];
            $short = 0;
            foreach ($rows as $r) {
                $counts[$r['urgency']]++;
                $short += $r['short'] > 0 ? 1 : 0;
                $kpi['starved'] += $r['starved'] ? 1 : 0;
            }

            foreach ($counts as $k => $n) {
                $kpi[$k] += $n;
            }
            $kpi['lines'] += count($rows);
            $kpi['short_lines'] += $short;
            $kpi['shops'] += $rows === [] ? 0 : 1;

            $result[] = [
                'id' => $shop['id'],
                'name' => $shop['name'],
                'horizon' => $horizonOf($shop['id']),
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
            'generated_at' => $forecast['generated_at'],
            'horizon' => $default,
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
     * One order line of a forecast row for horizon $h (a superset of what the monitor view needs).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function line(array $r, int $h): array
    {
        $belowMin = $r['position'] <= $r['min'];
        $coverLow = ($r['cover_days'] !== null && $r['cover_days'] < $h) || ($r['stockout_in'] !== null && $r['stockout_in'] <= $h);
        $urgency = isset(self::RANK[$r['level']]) ? $r['level'] : 'low';
        $tier = $r['level'] === 'out' ? 0
            : ($r['stockout_in'] !== null && $r['stockout_in'] <= 1 ? 1
            : ($coverLow ? 2 : 3));

        return [
            'item_id' => $r['item_id'],
            'item' => $r['item'],
            'group' => $r['group'],
            'shop_id' => $r['shop_id'],
            'stock' => $r['stock'],
            'stock_date' => $r['stock_date'],
            'estimated' => $r['est'],
            'in_transit' => $r['in_transit'],
            'min' => $r['min'],
            'max' => $r['max'],
            'speed' => $r['speed'],
            'cover' => $r['cover_days'],
            'stockout_in' => $r['stockout_in'],
            'stockout_date' => $r['stockout_date'],
            'abc' => $r['abc'],
            'xyz' => $r['xyz'],
            'confidence' => $r['confidence'],
            'rules_only' => $r['rules_only'],
            'clipped' => $r['clipped'],
            'stale' => $r['stale'],
            'censored_days' => $r['censored_days'],
            'level' => $r['level'],
            'reason' => $belowMin && $coverLow ? 'both' : ($belowMin ? 'min' : 'cover'),
            'urgency' => $urgency,
            'tier' => $tier,
            'forecast' => $r['F'][$h],
            'need' => (float) $r['order'][$h],
            'send' => (float) $r['order'][$h],
            'short' => 0.0,
            'starved' => false,
            'piece' => $r['piece'],
        ];
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
     * @param  array<int, string>  $shopNames
     * @return array{0: array<int, array<int, array<string, mixed>>>, 1: array<int, array<string, mixed>>}
     */
    private function allocate(array $rowsByShop, bool $configured, array $available, array $shopNames): array
    {
        $byItem = [];
        foreach ($rowsByShop as $shopId => $rows) {
            foreach ($rows as $i => $row) {
                $byItem[$row['item_id']][] = [$shopId, $i];
            }
        }

        $pick = [];
        foreach ($byItem as $itemId => $refs) {
            $demands = [];
            $piece = true;
            foreach ($refs as $n => [$shopId, $i]) {
                $r = $rowsByShop[$shopId][$i];
                $piece = $piece && $r['piece'];
                $demands[] = ['key' => $n, 'rank' => $r['tier'], 'cover' => $r['cover'], 'need' => $r['need']];
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
            $starved = false;
            $name = '';
            foreach ($refs as $n => [$shopId, $i]) {
                $r = &$rowsByShop[$shopId][$i];
                $r['send'] = (float) $send[$n];
                $r['short'] = $configured ? round($r['need'] - $r['send'], 6) : 0.0;
                $r['starved'] = $configured && $r['level'] === 'out' && $r['send'] <= 0;
                $worst = min($worst, self::RANK[$r['urgency']]);
                $minCover = $r['cover'] === null ? $minCover : ($minCover === null ? $r['cover'] : min($minCover, $r['cover']));
                $sendTotal += $r['send'];
                $starved = $starved || $r['starved'];
                $name = $r['item'];
                $shopsOut[] = [
                    'shop_id' => $shopId,
                    'shop' => (string) ($shopNames[$shopId] ?? '#'.$shopId),
                    'need' => $r['need'],
                    'send' => $r['send'],
                    'short' => $r['short'],
                    'urgency' => $r['urgency'],
                    'starved' => $r['starved'],
                ];
                unset($r);
            }
            usort($shopsOut, fn ($a, $b) => [self::RANK[$a['urgency']], -$a['need'], $a['shop']] <=> [self::RANK[$b['urgency']], -$b['need'], $b['shop']]);

            $short = $configured ? round($needTotal - $sendTotal, 6) : 0.0;
            $pick[] = [
                'item_id' => (int) $itemId,
                'item' => $name,
                'need' => round($needTotal, 6),
                'send' => round($sendTotal, 6),
                'available' => $have,
                'short' => $short,
                'short_pct' => $needTotal > 0 ? round($short / $needTotal * 100, 1) : 0.0,
                'urgency' => array_search($worst, self::RANK, true),
                'cover' => $minCover,
                'starved' => $starved,
                'shops' => $shopsOut,
            ];
        }

        // What the team prepares first: most urgent, then the lowest cover, then the biggest quantity.
        usort($pick, fn ($a, $b) => [self::RANK[$a['urgency']], $a['cover'] ?? INF, -$a['need'], $a['item']] <=> [self::RANK[$b['urgency']], $b['cover'] ?? INF, -$b['need'], $b['item']]);

        return [$rowsByShop, $pick];
    }

    /**
     * Current quantity of the main warehouse per item id, minus what open orders already claim.
     *
     * @return array<int, float>
     */
    private function warehouseStock(int $warehouseId): array
    {
        $stock = Stock::applyCurrent(DB::table('stocks'))
            ->where('stocks.shop_id', $warehouseId)
            ->pluck('stocks.qty', 'stocks.item_id')
            ->map(fn ($q) => (float) $q)
            ->all();

        $claimed = DB::table('order_items as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->whereIn('o.status', OrderStatus::open())
            ->groupBy('ol.item_id')
            ->selectRaw('ol.item_id as item_id, SUM(ol.qty) as qty')
            ->pluck('qty', 'item_id');

        foreach ($claimed as $itemId => $qty) {
            if (isset($stock[$itemId])) {
                $stock[$itemId] = max($stock[$itemId] - (float) $qty, 0.0);
            }
        }

        return $stock;
    }
}
