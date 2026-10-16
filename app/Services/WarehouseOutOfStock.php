<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Stock;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Items that are out of stock at the MAIN WAREHOUSE, found from the daily stock history.
 *
 * Metric dictionary
 * -----------------
 * R (ref)        the warehouse's latest stock_date (MAX over the warehouse's own rows)
 * G (chain)      the latest stock_date over all shops
 * state          unconfigured (no warehouse) | empty (no warehouse rows) | not_synced (R < G: the warehouse was
 *                not synced with the other shops, nothing is judged) | ok
 * candidate      an item with a positive warehouse quantity within config inventory.warehouse_history_days of R
 *                (never-stocked and long-dead items are not candidates; ruled items outside are only counted)
 * out            candidate whose latest warehouse row is older than R (reason "missing": 1C no longer reports
 *                it) OR whose row at R has qty <= 0 (reason "zero"); a negative qty is also out and carries the
 *                `negative` data-quality flag
 * partial        coverage = rows at R / rows at the previous warehouse sync date < inventory.warehouse_sync_min_coverage:
 *                the sync looks incomplete, the list is a warning only (the page shows no KPI figures)
 * in_stock_date  latest warehouse row with qty > 0; days_out = R - in_stock_date (missing: R - last row date)
 * speed          chain net sales per day over the last 7 COMPLETE days, shops only (successful sale receipts
 *                minus refunds); understated when shops were also out
 * shops/need     shops that have a line in the Ordering plan for the item (position <= min, cover or stock-out
 *                inside the shop's own horizon) and the sum of their order(H); the warehouse is never replenished
 * open_orders    quantity on orders in OrderStatus::open() (any shop)
 * abc            28-day chain revenue share of complete days (A <= 80 %, B <= 95 %, else C)
 * min/max        sum of the shops' rules (max > 0), warehouse excluded
 */
class WarehouseOutOfStock
{
    public const TTL = 60;

    public const SORTS = ['priority', 'name', 'days', 'speed', 'shops', 'need'];

    /** @var array<int, string> ordered histogram buckets: label => [from, to] days */
    public const BUCKETS = ['0-1' => [0, 1], '2-3' => [2, 3], '4-7' => [4, 7], '8-14' => [8, 14], '15+' => [15, PHP_INT_MAX]];

    /**
     * Everything that does not depend on the page filters (cached ~60 s per warehouse and sync dates).
     *
     * @return array<string, mixed>
     */
    public function data(?Carbon $now = null): array
    {
        $now ??= now();
        $warehouse = Warehouse::shop();
        $base = [
            'state' => 'unconfigured', 'warehouse' => $warehouse?->name, 'ref' => null, 'chain' => null, 'previous' => null,
            'coverage' => null, 'partial' => false, 'candidates' => 0, 'excluded' => 0, 'rows' => [], 'groups' => [],
            'kpi' => null, 'histogram' => [], 'history_days' => (int) config('inventory.warehouse_history_days'),
        ];
        if ($warehouse === null) {
            return $base;
        }

        $ref = DB::table('stocks')->where('shop_id', $warehouse->id)->max('stock_date');
        $chain = DB::table('stocks')->max('stock_date');
        if ($ref === null) {
            return ['state' => 'empty'] + $base;
        }
        $ref = substr((string) $ref, 0, 10);
        $chain = substr((string) $chain, 0, 10);
        $base['ref'] = $ref;
        $base['chain'] = $chain;
        if ($ref < $chain) {
            return ['state' => 'not_synced'] + $base;
        }

        return Cache::remember(
            $this->cacheKey((int) $warehouse->id, $ref, $now),
            self::TTL,
            fn () => $this->compute((int) $warehouse->id, $ref, $chain, $now->copy()->startOfDay(), $base),
        );
    }

    private function cacheKey(int $warehouseId, string $ref, Carbon $now): string
    {
        return 'wh-out-of-stock.v'.ShopAccess::salesVersion().'.w'.$warehouseId.'.r'.$ref.'.'.$now->toDateString();
    }

    /** Drops the cached result of the current warehouse sync so the next data() call recalculates. */
    public function forget(?Carbon $now = null): void
    {
        $warehouse = Warehouse::shop();
        $ref = $warehouse === null ? null : DB::table('stocks')->where('shop_id', $warehouse->id)->max('stock_date');
        if ($warehouse !== null && $ref !== null) {
            Cache::forget($this->cacheKey((int) $warehouse->id, substr((string) $ref, 0, 10), $now ?? now()));
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function compute(int $w, string $ref, string $chain, Carbon $today, array $base): array
    {
        $base['state'] = 'ok';
        $cutoff = Carbon::parse($ref)->subDays($base['history_days'])->toDateString();

        // Coverage of the latest sync against the previous warehouse sync.
        $previous = DB::table('stocks')->where('shop_id', $w)->where('stock_date', '<', $ref)->max('stock_date');
        if ($previous !== null) {
            $previous = substr((string) $previous, 0, 10);
            $now = DB::table('stocks')->where('shop_id', $w)->where('stock_date', $ref)->count();
            $before = DB::table('stocks')->where('shop_id', $w)->where('stock_date', $previous)->count();
            $base['previous'] = $previous;
            $base['coverage'] = $before > 0 ? round($now / $before, 3) : null;
            $base['partial'] = $base['coverage'] !== null && $base['coverage'] < (float) config('inventory.warehouse_sync_min_coverage');
        }

        // Candidates: last positive date per item inside the history cap.
        $positive = DB::table('stocks')->where('shop_id', $w)->where('qty', '>', 0)->where('stock_date', '>=', $cutoff)
            ->groupBy('item_id')->selectRaw('item_id, MAX(stock_date) as pos_date')->pluck('pos_date', 'item_id')->all();
        $base['candidates'] = count($positive);

        $latest = Stock::applyCurrent(DB::table('stocks')->where('shop_id', $w)->where('stock_date', '>=', $cutoff))
            ->get(['item_id', 'qty', 'stock_date'])->keyBy('item_id');

        $out = [];
        foreach ($positive as $itemId => $posDate) {
            $cur = $latest[$itemId] ?? null;
            if ($cur === null) {
                continue;
            }
            $curDate = substr((string) $cur->stock_date, 0, 10);
            $qty = (float) $cur->qty;
            if ($curDate < $ref) {
                $reason = 'missing';
                $since = $curDate;
            } elseif ($qty <= 0) {
                $reason = 'zero';
                $since = substr((string) $posDate, 0, 10);
            } else {
                continue;
            }
            $out[(int) $itemId] = [
                'item_id' => (int) $itemId,
                'reason' => $reason,
                'negative' => $qty < 0,
                'last_qty' => $qty,
                'last_date' => $curDate,
                'in_stock_date' => substr((string) $posDate, 0, 10),
                'days_out' => (int) Carbon::parse($since)->diffInDays(Carbon::parse($ref)),
            ];
        }

        // Rules of the shops (warehouse excluded): sums per item, and the ruled items outside the candidates.
        $rules = DB::table('item_order_rules as r')->where('r.shop_id', '!=', $w)->where('r.max', '>', 0)
            ->groupBy('r.item_id')->selectRaw('r.item_id as item_id, SUM(r.min) as min_sum, SUM(r.max) as max_sum')
            ->get()->keyBy('item_id');
        $base['excluded'] = $rules->keys()->diff(array_keys($positive))->count();

        if ($out === []) {
            $base['kpi'] = ['out' => 0, 'a' => 0, 'share' => 0.0, 'need' => 0.0, 'need_items' => 0, 'negative' => 0];
            $base['histogram'] = $this->histogram([]);

            return $base;
        }

        $ids = array_keys($out);
        $meta = collect();
        foreach (array_chunk($ids, 500) as $chunk) {
            $meta = $meta->concat(DB::table('items')->leftJoin('groups', 'groups.id', '=', 'items.group_id')
                ->whereIn('items.id', $chunk)->get(['items.id', 'items.name', 'items.mark', 'items.group_id', 'groups.name as group_name']));
        }
        $meta = $meta->keyBy('id');

        [$speed, $abc] = $this->sales($w, $today);
        $open = DB::table('order_items as ol')->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->whereIn('o.status', OrderStatus::open())->groupBy('ol.item_id')
            ->selectRaw('ol.item_id as item_id, SUM(ol.qty) as qty')->pluck('qty', 'item_id');
        [$shops, $need] = $this->demand($w, $ids);

        $groups = [];
        $rows = [];
        foreach ($out as $itemId => $row) {
            $m = $meta[$itemId] ?? null;
            if ($m === null) {
                continue;
            }
            if ($m->group_id !== null) {
                $groups[(int) $m->group_id] = (string) $m->group_name;
            }
            $rule = $rules[$itemId] ?? null;
            $rows[] = $row + [
                'name' => (string) $m->name,
                'sku' => $m->mark,
                'group_id' => $m->group_id === null ? null : (int) $m->group_id,
                'group' => $m->group_name,
                'abc' => $abc[$itemId] ?? 'C',
                'speed' => round($speed[$itemId] ?? 0.0, 3),
                'shops' => $shops[$itemId] ?? 0,
                'need' => round($need[$itemId] ?? 0.0, 3),
                'open_orders' => round((float) ($open[$itemId] ?? 0), 3),
                'min' => $rule === null ? null : (float) $rule->min_sum,
                'max' => $rule === null ? null : (float) $rule->max_sum,
            ];
        }
        asort($groups);

        $base['rows'] = $rows;
        $base['groups'] = $groups;
        $base['histogram'] = $this->histogram($rows);
        $base['kpi'] = [
            'out' => count($rows),
            'a' => count(array_filter($rows, fn (array $r) => $r['abc'] === 'A')),
            'share' => $base['candidates'] > 0 ? round(count($rows) / $base['candidates'] * 100, 1) : 0.0,
            'need' => round(array_sum(array_column($rows, 'need')), 3),
            'need_items' => count(array_filter($rows, fn (array $r) => $r['need'] > 0)),
            'negative' => count(array_filter($rows, fn (array $r) => $r['negative'])),
        ];

        return $base;
    }

    /**
     * Chain sales speed (7 complete days, per day) and ABC class (28-day revenue share), shops only.
     *
     * @return array{0: array<int, float>, 1: array<int, string>}
     */
    private function sales(int $w, Carbon $today): array
    {
        $rows = DB::table('receipt_items as ri')
            ->join('receipts', 'receipts.id', '=', 'ri.receipt_id')
            ->where('receipts.active', true)->where('ri.storno', false)
            ->where('receipts.shop_id', '!=', $w)
            ->where('receipts.created_at', '>=', $today->copy()->subDays(28)->toDateTimeString())
            ->where('receipts.created_at', '<', $today->toDateTimeString())
            ->groupBy('ri.item_id')
            ->selectRaw('ri.item_id as item_id, '
                .'SUM(CASE WHEN receipts.created_at >= ? THEN (CASE WHEN receipts.sell = 1 THEN ri.qty ELSE -ri.qty END) ELSE 0 END) as net7, '
                .'SUM(CASE WHEN receipts.sell = 1 THEN ri.total ELSE -ri.total END) as rev28', [$today->copy()->subDays(7)->toDateTimeString()])
            ->get();

        $speed = [];
        $revenue = [];
        foreach ($rows as $r) {
            $speed[(int) $r->item_id] = max((float) $r->net7, 0.0) / 7;
            $revenue[(int) $r->item_id] = max((float) $r->rev28, 0.0);
        }

        $abc = [];
        $total = array_sum($revenue);
        if ($total > 0) {
            arsort($revenue);
            $cum = 0.0;
            foreach ($revenue as $itemId => $rev) {
                if ($rev <= 0) {
                    break;
                }
                $cum += $rev;
                $share = $cum / $total;
                $abc[$itemId] = $share <= 0.80 + 1e-9 || $cum === $rev ? 'A' : ($share <= 0.95 + 1e-9 ? 'B' : 'C');
            }
        }

        return [$speed, $abc];
    }

    /**
     * Shops needing each item and their total order(H), from the Ordering plan (each shop's own horizon).
     *
     * @param  array<int, int>  $itemIds
     * @return array{0: array<int, int>, 1: array<int, float>}
     */
    private function demand(int $w, array $itemIds): array
    {
        $wanted = array_flip($itemIds);
        $forecast = app(ForecastService::class)->build(null, [$w]);
        $plan = app(OrderRecommendations::class)->plan($forecast, null, null);

        $shops = [];
        $need = [];
        foreach ($plan['shops'] as $shop) {
            foreach ($shop['rows'] as $line) {
                if (isset($wanted[$line['item_id']])) {
                    $shops[$line['item_id']] = ($shops[$line['item_id']] ?? 0) + 1;
                    $need[$line['item_id']] = ($need[$line['item_id']] ?? 0.0) + $line['need'];
                }
            }
        }

        return [$shops, $need];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{A: int, B: int, C: int}>
     */
    private function histogram(array $rows): array
    {
        $out = array_map(fn () => ['A' => 0, 'B' => 0, 'C' => 0], self::BUCKETS);
        foreach ($rows as $r) {
            foreach (self::BUCKETS as $label => [$from, $to]) {
                if ($r['days_out'] >= $from && $r['days_out'] <= $to) {
                    $out[$label][$r['abc']]++;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Normalizes raw filter input (Livewire properties or a query string).
     *
     * @param  array<string, mixed>  $in
     * @return array{group: int|null, abc: string, reason: string, needed: bool, min_days: int, q: string, sort: string, dir: string}
     */
    public static function filters(array $in): array
    {
        $needed = $in['needed'] ?? true;

        return [
            'group' => is_numeric($in['group'] ?? null) && (int) $in['group'] > 0 ? (int) $in['group'] : null,
            'abc' => in_array($in['abc'] ?? '', ['A', 'B', 'C'], true) ? $in['abc'] : '',
            'reason' => in_array($in['reason'] ?? '', ['missing', 'zero'], true) ? $in['reason'] : '',
            'needed' => $needed === true || $needed === 1 || $needed === '1' || $needed === 'true' || $needed === 'on',
            'min_days' => max(0, (int) ($in['min_days'] ?? 0)),
            'q' => trim((string) ($in['q'] ?? '')),
            'sort' => in_array($in['sort'] ?? '', self::SORTS, true) ? $in['sort'] : 'priority',
            'dir' => ($in['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Applies the filters and the sort to the cached rows (cheap, per request).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $f  from filters()
     * @return array<int, array<string, mixed>>
     */
    public function apply(array $rows, array $f): array
    {
        $barcodeIds = [];
        if ($f['q'] !== '') {
            $barcodeIds = array_flip(DB::table('barcodes')->where('gtin', 'like', '%'.$f['q'].'%')->limit(200)->pluck('item_id')->all());
        }

        $rows = array_values(array_filter($rows, function (array $r) use ($f, $barcodeIds) {
            return ($f['group'] === null || $r['group_id'] === $f['group'])
                && ($f['abc'] === '' || $r['abc'] === $f['abc'])
                && ($f['reason'] === '' || $r['reason'] === $f['reason'])
                && (! $f['needed'] || $r['need'] > 0 || $r['shops'] > 0)
                && $r['days_out'] >= $f['min_days']
                && ($f['q'] === '' || isset($barcodeIds[$r['item_id']])
                    || mb_stripos($r['name'], $f['q']) !== false || mb_stripos((string) $r['sku'], $f['q']) !== false);
        }));

        $abcRank = ['A' => 0, 'B' => 1, 'C' => 2];
        $priority = fn (array $a, array $b) => [-$a['shops'], $abcRank[$a['abc']], -$a['days_out'], $a['name']]
            <=> [-$b['shops'], $abcRank[$b['abc']], -$b['days_out'], $b['name']];
        $sign = $f['dir'] === 'asc' ? 1 : -1;
        $key = ['days' => 'days_out', 'speed' => 'speed', 'shops' => 'shops', 'need' => 'need', 'name' => 'name'][$f['sort']] ?? null;

        usort($rows, $key === null
            ? $priority
            : fn (array $a, array $b) => (($a[$key] <=> $b[$key]) * $sign) ?: $priority($a, $b));

        return $rows;
    }
}
