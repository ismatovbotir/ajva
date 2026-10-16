<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assortment (product matrix) analysis: ABC x XYZ, dead and slow stock, A items out of stock.
 *
 * Metric dictionary
 *  - Sale = receipts.active = 1 AND receipts.sell = 1, storno lines (receipt_items.storno = 1) excluded. Refunds and
 *    cancelled receipts are NOT netted (same rule as the Analytics page); revenue = SUM(receipt_items.total).
 *  - Window: whole weeks. The period is cut to the last W = floor(days / 7) full 7-day blocks ending on the "to" day, so
 *    every weekday is counted the same number of times. 12 weeks (84 days) is the recommended period.
 *  - ABC per item over the selected shops together, by revenue share in the window: A while the cumulative share is
 *    <= 80 % (the first item is always A), B <= 95 %, else C (the same rule as the Ordering report, which does it per shop
 *    over 28 days).
 *  - XYZ = coefficient of variation of the WEEKLY quantity over the W weeks (weeks without a sale count as 0):
 *    X <= 0.25, Y <= 0.5, else Z. Weekly buckets because daily counts mostly measure the weekday shape; the Ordering report
 *    uses daily 0.5 / 1 for its forecast. Needs W >= 8, otherwise XYZ is not computed (never guessed). An item that was
 *    launched inside the window has zero weeks before its launch and therefore reads as Z.
 *  - On hand = current stock (Stock::applyCurrent) summed over the selected shops, negative rows counted as 0.
 *  - Cover days = on hand / (quantity sold in the window / days in the window).
 *  - Dead stock = on hand > 0 and no sale at all in the window. Slow = cover > SLOW_COVER_DAYS.
 *  - A item at risk = class A and out of stock (stock <= 0) in at least one selected shop that has a stock row for it.
 *  - Stock value (cost price, config inventory.cost_price_id) only when $withProfit; items without a cost price are
 *    counted and left out of the value, never treated as zero cost.
 *
 * Scale: one grouped pass over receipt_items for the window (rows = items x weeks sold, thousands) plus one grouped pass
 * over the current stock; everything else is arithmetic on those two result sets.
 */
class AssortmentAnalysis
{
    public const A_SHARE = 0.80;

    public const B_SHARE = 0.95;

    public const X_CV = 0.25;

    public const Y_CV = 0.5;

    public const MIN_WEEKS_XYZ = 8;

    public const SLOW_COVER_DAYS = 90;

    public const STALE_STOCK_DAYS = 3;

    public const LIST_ROWS = 25;

    /**
     * @param  array<int, int>  $shopIds  shops to analyse (already intersected with the viewer's scope)
     * @return array<string, mixed>
     */
    public function build(Carbon $from, Carbon $to, array $shopIds, bool $withProfit = false): array
    {
        $to = $to->copy()->endOfDay();
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $weeks = intdiv($days, 7);
        // Whole weeks only: drop the oldest remainder days.
        $start = $weeks >= 1 ? $to->copy()->startOfDay()->subDays($weeks * 7 - 1) : $from->copy()->startOfDay();
        $used = $weeks >= 1 ? $weeks * 7 : $days;
        $xyzOn = $weeks >= self::MIN_WEEKS_XYZ;

        $sold = $this->sales($start, $to, $shopIds);
        $items = $this->classify($sold, $weeks, $xyzOn);
        $stock = $this->stock($shopIds);

        $total = array_sum(array_column($items, 'rev'));

        $lists = $this->stockLists($items, $stock, $used);
        $atRisk = [];
        foreach ($items as $id => $row) {
            if ($row['abc'] === 'A' && isset($stock[$id]) && $stock[$id]['out_shops'] > 0) {
                $atRisk[] = $id;
            }
        }
        usort($atRisk, fn ($a, $b) => $items[$b]['rev'] <=> $items[$a]['rev']);

        $top = array_slice(array_keys($items), 0, 30);
        $ids = array_values(array_unique([...$top, ...array_slice($atRisk, 0, self::LIST_ROWS), ...array_column($lists['dead_rows'], 'id'), ...array_column($lists['slow_rows'], 'id')]));
        $names = $ids === [] ? [] : $this->names($ids);
        $costs = $withProfit ? $this->costs(array_merge($ids, $lists['dead_ids'], $lists['slow_ids'])) : [];

        $classCount = ['A' => 0, 'B' => 0, 'C' => 0];
        $matrix = [];
        foreach (['A', 'B', 'C'] as $a) {
            foreach (($xyzOn ? ['X', 'Y', 'Z'] : ['-']) as $x) {
                $matrix[$a][$x] = ['items' => 0, 'rev' => 0.0, 'share' => 0.0];
            }
        }
        foreach ($items as $row) {
            $classCount[$row['abc']]++;
            $cell = &$matrix[$row['abc']][$xyzOn ? $row['xyz'] : '-'];
            $cell['items']++;
            $cell['rev'] += $row['rev'];
            unset($cell);
        }
        foreach ($matrix as $a => $cols) {
            foreach ($cols as $x => $cell) {
                $matrix[$a][$x]['share'] = $total > 0 ? $cell['rev'] / $total * 100 : 0.0;
            }
        }

        $row = function (int $id, array $extra = []) use ($items, $names, $stock, $used, $costs, $total, $withProfit) {
            $r = $items[$id] ?? null;
            $on = $stock[$id]['on_hand'] ?? null;
            $cover = ($r && $on !== null && $r['qty'] > 0) ? $on / ($r['qty'] / $used) : null;
            $cost = $costs[$id] ?? null;

            return $extra + [
                'id' => $id,
                'name' => $names[$id] ?? '#'.$id,
                'abc' => $r['abc'] ?? null,
                'xyz' => $r['xyz'] ?? null,
                'qty' => $r['qty'] ?? 0.0,
                'rev' => $r['rev'] ?? 0.0,
                'share' => $r && $total > 0 ? $r['rev'] / $total * 100 : 0.0,
                'on_hand' => $on,
                'cover' => $cover,
                'out_shops' => $stock[$id]['out_shops'] ?? 0,
                'shops' => $stock[$id]['shops'] ?? 0,
                'value' => ($withProfit && $cost !== null && $on !== null) ? $on * $cost : null,
            ];
        };

        $stockValue = null;
        if ($withProfit) {
            $deadValue = 0.0;
            $deadNoCost = 0;
            foreach ($lists['dead_ids'] as $id) {
                if (($costs[$id] ?? 0) > 0) {
                    $deadValue += $stock[$id]['on_hand'] * $costs[$id];
                } else {
                    $deadNoCost++;
                }
            }
            $slowValue = 0.0;
            foreach ($lists['slow_ids'] as $id) {
                if (($costs[$id] ?? 0) > 0) {
                    $slowValue += $stock[$id]['on_hand'] * $costs[$id];
                }
            }
            $stockValue = ['dead' => $deadValue, 'dead_without_cost' => $deadNoCost, 'slow' => $slowValue];
        }

        $stockDates = array_filter(array_column($stock, 'as_of'));
        $asOf = $stockDates === [] ? null : max($stockDates);

        $aCount = $classCount['A'];
        $count = count($items);

        return [
            'window' => ['from' => $start->toDateString(), 'to' => $to->toDateString(), 'days' => $used, 'weeks' => $weeks, 'requested_days' => $days, 'xyz' => $xyzOn],
            'kpi' => [
                'items' => $count,
                'revenue' => $total,
                'a_items' => $aCount,
                'a_items_share' => $count > 0 ? $aCount / $count * 100 : 0.0,
                'class_count' => $classCount,
                'az_items' => $xyzOn ? $matrix['A']['Z']['items'] : null,
                'cz_items' => $xyzOn ? $matrix['C']['Z']['items'] : null,
                'cz_share_items' => ($xyzOn && $count > 0) ? $matrix['C']['Z']['items'] / $count * 100 : null,
                'cz_share_revenue' => $xyzOn ? $matrix['C']['Z']['share'] : null,
                'a_at_risk' => count($atRisk),
                'dead' => count($lists['dead_ids']),
                'slow' => count($lists['slow_ids']),
                'slow_days' => self::SLOW_COVER_DAYS,
                'stock_as_of' => $asOf,
                'stock_stale' => $asOf !== null && Carbon::parse($asOf)->lt(now()->startOfDay()->subDays(self::STALE_STOCK_DAYS)),
                'sold_without_stock_row' => count(array_filter(array_keys($items), fn ($id) => ! isset($stock[$id]))),
                'negative_stock_rows' => array_sum(array_column($stock, 'negative')),
                'stock_value' => $stockValue,
            ],
            'matrix' => $xyzOn ? $matrix : null,
            'abc_summary' => $matrix === [] ? [] : array_map(fn ($a) => ['items' => $classCount[$a], 'share' => $total > 0 ? array_sum(array_column($matrix[$a], 'rev')) / $total * 100 : 0.0], ['A' => 'A', 'B' => 'B', 'C' => 'C']),
            'top' => array_map($row, $top),
            'at_risk' => array_map($row, array_slice($atRisk, 0, self::LIST_ROWS)),
            'dead' => array_map(fn (array $d) => $row($d['id'], ['dead' => true]), $lists['dead_rows']),
            'slow' => array_map(fn (array $d) => $row($d['id']), $lists['slow_rows']),
            'profit' => $withProfit,
        ];
    }

    /**
     * One grouped pass: quantity, revenue, weeks with a sale and the sum of squared weekly quantities per item.
     *
     * @param  array<int, int>  $shopIds
     * @return array<int, array{qty: float, rev: float, weeks_sold: int, q2: float}>
     */
    private function sales(Carbon $from, Carbon $to, array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }

        $weekExpr = DB::getDriverName() === 'sqlite'
            ? 'CAST((julianday(date(r.created_at)) - julianday(?)) / 7 AS INTEGER)'
            : 'FLOOR(DATEDIFF(DATE(r.created_at), ?) / 7)';

        $inner = ShopAccess::restrictTo(
            DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id'),
            'r.shop_id',
            $shopIds
        )
            ->where('r.active', true)
            ->where('r.sell', true)
            ->where('ri.storno', false)
            ->whereBetween('r.created_at', [$from, $to])
            ->groupBy('ri.item_id', 'wk')
            ->selectRaw("ri.item_id AS item_id, {$weekExpr} AS wk, SUM(ri.qty) AS q, SUM(ri.total) AS s", [$from->toDateString()]);

        $rows = DB::query()->fromSub($inner, 'w')
            ->groupBy('w.item_id')
            ->selectRaw('w.item_id AS item_id, SUM(w.q) AS qty, SUM(w.s) AS rev, COUNT(*) AS weeks_sold, SUM(w.q * w.q) AS q2')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            if ((float) $r->qty > 0 && (float) $r->rev > 0) {
                $out[(int) $r->item_id] = ['qty' => (float) $r->qty, 'rev' => (float) $r->rev, 'weeks_sold' => (int) $r->weeks_sold, 'q2' => (float) $r->q2];
            }
        }

        return $out;
    }

    /**
     * ABC (cumulative revenue share) and XYZ (weekly CV) per item, sorted by revenue.
     *
     * @param  array<int, array<string, float|int>>  $sold
     * @return array<int, array<string, mixed>>
     */
    private function classify(array $sold, int $weeks, bool $xyzOn): array
    {
        uasort($sold, fn ($a, $b) => $b['rev'] <=> $a['rev']);
        $total = array_sum(array_column($sold, 'rev'));
        $cum = 0.0;
        $first = true;
        foreach ($sold as $id => &$r) {
            $cum += $r['rev'];
            $share = $total > 0 ? $cum / $total : 1.0;
            $r['abc'] = ($first || $share <= self::A_SHARE + 1e-9) ? 'A' : ($share <= self::B_SHARE + 1e-9 ? 'B' : 'C');
            $first = false;

            $r['cv'] = null;
            $r['xyz'] = null;
            if ($xyzOn) {
                $mean = $r['qty'] / $weeks;
                $var = max(0.0, $r['q2'] / $weeks - $mean * $mean);
                $r['cv'] = $mean > 0 ? sqrt($var) / $mean : null;
                $r['xyz'] = $r['cv'] === null ? 'Z' : ($r['cv'] <= self::X_CV ? 'X' : ($r['cv'] <= self::Y_CV ? 'Y' : 'Z'));
            }
        }
        unset($r);

        return $sold;
    }

    /**
     * Current stock per item over the selected shops.
     *
     * @param  array<int, int>  $shopIds
     * @return array<int, array{on_hand: float, out_shops: int, shops: int, negative: int, as_of: ?string}>
     */
    private function stock(array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }

        $rows = Stock::applyCurrent(DB::table('stocks'))
            ->whereIn('stocks.shop_id', $shopIds)
            ->groupBy('stocks.item_id')
            ->selectRaw(
                'stocks.item_id AS item_id, '
                .'SUM(CASE WHEN stocks.qty > 0 THEN stocks.qty ELSE 0 END) AS on_hand, '
                .'SUM(CASE WHEN stocks.qty <= 0 THEN 1 ELSE 0 END) AS out_shops, '
                .'SUM(CASE WHEN stocks.qty < 0 THEN 1 ELSE 0 END) AS negative, '
                .'COUNT(*) AS shops, MAX(stocks.stock_date) AS as_of'
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->item_id] = [
                'on_hand' => (float) $r->on_hand,
                'out_shops' => (int) $r->out_shops,
                'shops' => (int) $r->shops,
                'negative' => (int) $r->negative,
                'as_of' => $r->as_of === null ? null : substr((string) $r->as_of, 0, 10),
            ];
        }

        return $out;
    }

    /**
     * Dead and slow stock (ids for the totals, the biggest rows for the lists).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $stock
     * @return array{dead_ids: array<int, int>, slow_ids: array<int, int>, dead_rows: array<int, array{id: int}>, slow_rows: array<int, array{id: int}>}
     */
    private function stockLists(array $items, array $stock, int $days): array
    {
        $dead = [];
        $slow = [];
        foreach ($stock as $id => $s) {
            if ($s['on_hand'] <= 0) {
                continue;
            }
            if (! isset($items[$id])) {
                $dead[$id] = $s['on_hand'];
            } elseif ($items[$id]['qty'] > 0 && $s['on_hand'] / ($items[$id]['qty'] / $days) > self::SLOW_COVER_DAYS) {
                $slow[$id] = $s['on_hand'] / ($items[$id]['qty'] / $days);
            }
        }
        arsort($dead);
        arsort($slow);

        return [
            'dead_ids' => array_keys($dead),
            'slow_ids' => array_keys($slow),
            'dead_rows' => array_map(fn ($id) => ['id' => (int) $id], array_slice(array_keys($dead), 0, self::LIST_ROWS)),
            'slow_rows' => array_map(fn ($id) => ['id' => (int) $id], array_slice(array_keys($slow), 0, self::LIST_ROWS)),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('items')->whereIn('id', $chunk)->pluck('name', 'id') as $id => $name) {
                $out[(int) $id] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * Cost price per item (only items with a cost > 0; the rest are unknown, never zero).
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function costs(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            foreach (DB::table('item_prices')->where('price_id', (int) config('inventory.cost_price_id'))->whereIn('item_id', $chunk)->where('value', '>', 0)->pluck('value', 'item_id') as $id => $value) {
                $out[(int) $id] = (float) $value;
            }
        }

        return $out;
    }
}
