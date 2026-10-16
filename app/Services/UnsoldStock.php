<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock without sales: items that are on the shelf (current stock > 0) but had NO sale in the selected period.
 *
 * Question it answers: "where is cash lying around that does not move, and what should we do with it?".
 *
 * Metric dictionary
 *  - Sale = receipts.active = 1 AND receipts.sell = 1, storno lines excluded; refunds and cancelled receipts are not netted
 *    (an item that was only refunded in the period still counts as unsold). Period = the day range, inclusive.
 *  - On hand = current stock (Stock::applyCurrent: the newest snapshot per item and shop; history is never summed),
 *    only rows with qty > 0, summed over the selected shops. Stock is 1C's snapshot ("as of" is shown; a snapshot
 *    older than STALE_STOCK_DAYS is flagged).
 *  - Unsold item = on hand > 0 in the selected shops and no sale of it in ANY of the selected shops in the period.
 *  - Unsold item in a shop = stock > 0 in that shop and no sale of it in THAT shop (it may sell elsewhere: a transfer
 *    candidate). Both views come from the same two result sets.
 *  - Last sale = the newest sale date of the item (in the selected shops) before the end of the period; null = never
 *    sold in the data we have. Computed only for the rows a page shows (lastSales()), because it reads the whole receipt
 *    history of those items.
 *  - Value at cost = on hand x cost price (config inventory.cost_price_id), only when $withProfit; items without a cost
 *    price are counted and left out of the value, never treated as zero cost.
 *  - A new item that just arrived has stock and no sales yet: it appears here until it sells, so judge short periods
 *    with care (the page recommends 60 to 90 days).
 *
 * Scale: one grouped pass over the current stock, one DISTINCT (item, shop) pass over the sale lines of the period
 * (rows = items x shops sold, thousands), arithmetic in PHP on those two sets, lists capped at MAX_ROWS (totals are exact).
 */
class UnsoldStock
{
    public const MAX_ROWS = 5000;

    public const STALE_STOCK_DAYS = 3;

    public const SHORT_PERIOD_DAYS = 28;

    /**
     * @param  array<int, int>  $shopIds  already intersected with the viewer's scope
     * @return array<string, mixed>
     */
    public function build(Carbon $from, Carbon $to, array $shopIds, bool $withProfit = false): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $days = (int) $start->diffInDays($end->copy()->startOfDay()) + 1;

        $stock = $this->stock($shopIds);
        $sold = $this->soldPairs($start, $end, $shopIds);

        $soldItems = [];
        foreach (array_keys($sold) as $key) {
            $soldItems[(int) explode(':', (string) $key)[0]] = true;
        }

        $stockedItems = [];
        $unsoldItems = [];   // item_id => [on_hand, shops]
        $unsoldPairs = [];   // [item_id, shop_id, qty]
        $shopTotals = [];
        foreach ($stock as $row) {
            [$item, $shop, $qty] = $row;
            $stockedItems[$item] = true;
            if (! isset($sold[$item.':'.$shop])) {
                $unsoldPairs[] = $row;
                $shopTotals[$shop] = ($shopTotals[$shop] ?? 0) + 1;
            }
            if (! isset($soldItems[$item])) {
                $unsoldItems[$item] ??= ['on_hand' => 0.0, 'shops' => 0];
                $unsoldItems[$item]['on_hand'] += $qty;
                $unsoldItems[$item]['shops']++;
            }
        }

        uasort($unsoldItems, fn ($a, $b) => $b['on_hand'] <=> $a['on_hand']);
        usort($unsoldPairs, fn ($a, $b) => $b[2] <=> $a[2]);

        $pairCount = count($unsoldPairs);
        $itemCount = count($unsoldItems);
        $keptItems = array_slice($unsoldItems, 0, self::MAX_ROWS, true);
        $keptPairs = array_slice($unsoldPairs, 0, self::MAX_ROWS);

        $meta = $this->itemMeta(array_values(array_unique([...array_keys($unsoldItems), ...array_column($keptPairs, 0)])));
        $shopNames = DB::table('shops')->whereIn('id', array_keys($shopTotals))->pluck('name', 'id');
        $costs = $withProfit ? $this->costs(array_keys($meta)) : [];

        $value = null;
        if ($withProfit) {
            $sum = 0.0;
            $missing = 0;
            foreach ($unsoldItems as $id => $u) {
                if (($costs[$id] ?? 0) > 0) {
                    $sum += $u['on_hand'] * $costs[$id];
                } else {
                    $missing++;
                }
            }
            $value = ['unsold' => $sum, 'without_cost' => $missing];
        }

        $items = [];
        foreach ($keptItems as $id => $u) {
            $items[] = [
                'id' => $id,
                'name' => $meta[$id]['name'] ?? '#'.$id,
                'group_id' => $meta[$id]['group_id'] ?? null,
                'group' => $meta[$id]['group'] ?? null,
                'on_hand' => $u['on_hand'],
                'shops' => $u['shops'],
                'value' => ($withProfit && ($costs[$id] ?? 0) > 0) ? $u['on_hand'] * $costs[$id] : null,
            ];
        }
        $pairs = array_map(fn (array $p) => [
            'item_id' => $p[0],
            'shop_id' => $p[1],
            'name' => $meta[$p[0]]['name'] ?? '#'.$p[0],
            'group_id' => $meta[$p[0]]['group_id'] ?? null,
            'group' => $meta[$p[0]]['group'] ?? null,
            'shop' => (string) ($shopNames[$p[1]] ?? '#'.$p[1]),
            'on_hand' => $p[2],
            'value' => ($withProfit && ($costs[$p[0]] ?? 0) > 0) ? $p[2] * $costs[$p[0]] : null,
        ], $keptPairs);

        // Which groups hold the most unsold items (over all unsold items, not only the kept rows).
        $groups = $this->groupSummary(array_keys($unsoldItems), array_keys($stockedItems));

        $dates = array_filter(array_column($this->stockDates($shopIds), 'as_of'));
        $asOf = $dates === [] ? null : max($dates);

        return [
            'window' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days, 'short' => $days < self::SHORT_PERIOD_DAYS],
            'kpi' => [
                'stocked_items' => count($stockedItems),
                'unsold_items' => $itemCount,
                'unsold_share' => count($stockedItems) > 0 ? $itemCount / count($stockedItems) * 100 : 0.0,
                'unsold_units' => array_sum(array_column($unsoldItems, 'on_hand')),
                'unsold_pairs' => $pairCount,
                'stock_as_of' => $asOf,
                'stock_stale' => $asOf !== null && Carbon::parse($asOf)->lt(now()->startOfDay()->subDays(self::STALE_STOCK_DAYS)),
                'value' => $value,
                'truncated' => $itemCount > self::MAX_ROWS || $pairCount > self::MAX_ROWS,
            ],
            'groups' => $groups,
            'items' => $items,
            'pairs' => $pairs,
            'profit' => $withProfit,
        ];
    }

    /**
     * Newest sale date per item (item mode) or per item and shop (shop mode) up to the end of the period, for a SMALL set
     * of items (a page of the list). null = never sold in the data we have.
     *
     * @param  array<int, int>  $itemIds
     * @param  array<int, int>  $shopIds
     * @return array<string, ?string> key "item" or "item:shop"
     */
    public function lastSales(array $itemIds, array $shopIds, Carbon $to, bool $perShop): array
    {
        $out = [];
        if ($itemIds === [] || $shopIds === []) {
            return $out;
        }

        $q = ShopAccess::restrictTo(
            DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id'),
            'r.shop_id',
            $shopIds
        )
            ->whereIn('ri.item_id', $itemIds)
            ->where('r.active', true)
            ->where('r.sell', true)
            ->where('ri.storno', false)
            ->where('r.created_at', '<=', $to->copy()->endOfDay());

        $rows = $perShop
            ? $q->groupBy('ri.item_id', 'r.shop_id')->selectRaw('ri.item_id AS item_id, r.shop_id AS shop_id, MAX(r.created_at) AS last_at')->get()
            : $q->groupBy('ri.item_id')->selectRaw('ri.item_id AS item_id, MAX(r.created_at) AS last_at')->get();

        foreach ($rows as $r) {
            $out[$perShop ? $r->item_id.':'.$r->shop_id : (string) $r->item_id] = substr((string) $r->last_at, 0, 10);
        }

        return $out;
    }

    /**
     * Current stock rows with a positive quantity.
     *
     * @param  array<int, int>  $shopIds
     * @return array<int, array{0: int, 1: int, 2: float}> [item_id, shop_id, qty]
     */
    private function stock(array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }

        $rows = Stock::applyCurrent(DB::table('stocks'))
            ->whereIn('stocks.shop_id', $shopIds)
            ->where('stocks.qty', '>', 0)
            ->get(['stocks.item_id', 'stocks.shop_id', 'stocks.qty']);

        return $rows->map(fn ($r) => [(int) $r->item_id, (int) $r->shop_id, (float) $r->qty])->all();
    }

    /** @return array<int, array{as_of: ?string}> */
    private function stockDates(array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }

        $max = Stock::applyCurrent(DB::table('stocks'))->whereIn('stocks.shop_id', $shopIds)->max('stocks.stock_date');

        return [['as_of' => $max === null ? null : substr((string) $max, 0, 10)]];
    }

    /**
     * The (item, shop) pairs that had at least one sale line in the period.
     *
     * @param  array<int, int>  $shopIds
     * @return array<string, true> "item:shop" => true
     */
    private function soldPairs(Carbon $from, Carbon $to, array $shopIds): array
    {
        if ($shopIds === []) {
            return [];
        }

        $rows = ShopAccess::restrictTo(
            DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id'),
            'r.shop_id',
            $shopIds
        )
            ->where('r.active', true)
            ->where('r.sell', true)
            ->where('ri.storno', false)
            ->whereBetween('r.created_at', [$from, $to])
            ->groupBy('ri.item_id', 'r.shop_id')
            ->selectRaw('ri.item_id AS item_id, r.shop_id AS shop_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->item_id.':'.$r->shop_id] = true;
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{name: string, group_id: ?int, group: ?string}>
     */
    private function itemMeta(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('items')->leftJoin('groups', 'groups.id', '=', 'items.group_id')
                ->whereIn('items.id', $chunk)->get(['items.id', 'items.name', 'items.group_id', 'groups.name as group_name']);
            foreach ($rows as $r) {
                $out[(int) $r->id] = ['name' => (string) $r->name, 'group_id' => $r->group_id === null ? null : (int) $r->group_id, 'group' => $r->group_name];
            }
        }

        return $out;
    }

    /**
     * Groups with the most unsold items and what share of their stocked items that is.
     *
     * @param  array<int, int>  $unsold
     * @param  array<int, int>  $stocked
     * @return array<int, array{group: string, unsold: int, stocked: int, share: float}>
     */
    private function groupSummary(array $unsold, array $stocked): array
    {
        $groupOf = [];
        foreach (array_chunk($stocked, 500) as $chunk) {
            $rows = DB::table('items')->leftJoin('groups', 'groups.id', '=', 'items.group_id')->whereIn('items.id', $chunk)->get(['items.id', 'groups.name as group_name']);
            foreach ($rows as $r) {
                $groupOf[(int) $r->id] = $r->group_name ?? null;
            }
        }

        $unsoldSet = array_flip($unsold);
        $acc = [];
        foreach ($stocked as $id) {
            $g = $groupOf[$id] ?? null;
            $key = $g ?? '';
            $acc[$key] ??= ['unsold' => 0, 'stocked' => 0];
            $acc[$key]['stocked']++;
            if (isset($unsoldSet[$id])) {
                $acc[$key]['unsold']++;
            }
        }
        $out = [];
        foreach ($acc as $name => $a) {
            if ($a['unsold'] > 0) {
                $out[] = ['group' => $name, 'unsold' => $a['unsold'], 'stocked' => $a['stocked'], 'share' => $a['unsold'] / $a['stocked'] * 100];
            }
        }
        // Most unsold first; the items without a group come after a named group of the same size.
        usort($out, fn ($x, $y) => [$y['unsold'], $x['group'] === '' ? 1 : 0, $x['group']] <=> [$x['unsold'], $y['group'] === '' ? 1 : 0, $y['group']]);

        return array_slice($out, 0, 10);
    }

    /**
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
