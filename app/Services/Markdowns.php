<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\InTransit;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales growth > Markdown and promo. Two views, both SIGNALS TO REVIEW and a suggested start to test and adjust,
 * never an instruction. Every threshold lives in config/markdown.php. There is NO shelf-life data in the system:
 * candidates are slow stock, not expiry risk. Case / pallet effects are not modelled (units are whole pieces).
 *
 * Metric dictionary
 * -----------------
 * Eligible shops  the viewer's shops, not shops.is_disabled, not the main warehouse (Warehouse::shopId()).
 * Sale            active and sell receipt, storno lines excluded, refunds not netted (UnsoldStock::soldPairs, the SAME
 *                 definition as Stock without sales). Period = whole days from..to, `to` never later than yesterday.
 *
 * TAB 1: CANDIDATES per (item, shop)
 *  On hand        current stock (Stock::applyCurrent) REPORTED by the shop's latest sync (Stock::applyReported), qty > 0.
 *                 Pairs not reported are only COUNTED (kpi.not_reported), never judged.
 *  Excluded       a shop with less than min_shop_history_days of data (first receipt or first stock row), an item first seen
 *                 (first receipt line or first stock row, anywhere) less than min_item_age_days ago, a pair with an open
 *                 order of the SAME shop and item (App\Support\InTransit::apply, drafts and received orders do not count).
 *                 A pair with stock is by definition one that "had stock", so a never-stocked pair cannot appear.
 *  Delist         a pair whose item order rule is min = 0 and max = 0 and that still has stock: listed separately.
 *  Speed          ForecastService row (rule pairs only, includeUnlisted): speed is used when the row has one and confidence
 *                 is not 'none'; confidence 'low' is marked. No speed (no rule, confidence none, or low with speed 0) =
 *                 cover is NOT computed ("no trusted speed"), it is never read as infinite cover; such a pair can only be a
 *                 candidate through "no sale in the period" or "over max". A trusted speed of 0 = cover above the cap.
 *  Cover          on hand / speed, shown capped as ">cover_cap_days d".
 *  Candidate      no sale in the period, OR cover > cover_days, OR (max rule > 0 and on hand > over_max_multiple x max).
 *  Small stock    a candidate needs on hand >= min_units AND, when a sell price is known, value >= min_value; below it
 *                 the pair is only counted (kpi.small_stock). A pair with no sell price is kept and shown "no price".
 *  Sell price     the LOWEST positive price of the item over every price type except inventory.cost_price_id. Value at sell
 *                 price is shown to everyone; value at cost ONLY with ProfitAccess (items without a cost are counted).
 *  Depth          suggested start (test and adjust): cover rung (>= 90 d 10 %, >= 180 d 20 %), no sale in the period 20 %,
 *                 no sale for old_sale_days+ 30 % (never sold: the item must be that old), over max only 10 %. The DEEPER
 *                 rung wins. With ProfitAccess the depth is capped so the price does not go below cost (shown when it
 *                 limits the depth; no cost = "cost unknown"). Fresh groups (fresh_group_ids): "expiry: check by hand", no depth.
 *  Transfer first another selected shop (for an operator only his own shops) sells the item with a trusted speed and
 *                 < transfer_cover_days of cover (est / speed) and ForecastService orders >= 1 piece for it at the
 *                 transfer horizon: target shop and quantity = min(on hand, that order).
 *  Seasonal       units of the item sold in the same period last year (in the selected shops) >= seasonal_min_units.
 *
 * TAB 2: DISCOUNT EFFECTIVENESS from receipt lines
 *  Line discount  sum - totalSum (receipt_items.sum - total), discounted when above line_min_depth of the line sum.
 *  Discounted day a day when >= day_min_share of the item's units sold carry a line discount and the depths of those lines
 *                 are within depth_spread of each other. Full-price day: <= full_price_max_share discounted (days in
 *                 between are in neither group). Only days with stock > 0 in the daily stock rows (a missing day is
 *                 unknown) and not a silent receipt-feed shop-day (SalesGaps::silentDays) count; storno excluded; units
 *                 are net of refunds of the SAME day, clamped at 0 (inventory.refund_netting).
 *  Lift           units per in-stock day on discounted days / baseline, baseline = the full-price mean per weekday weighted
 *                 by the weekday mix of the discounted days (a weekday without full-price days is dropped and the weights
 *                 renormalised). Needs >= min_discounted_days and >= min_full_price_days, else "n/a".
 *  Give-away      SUM(sum - totalSum) of the discounted lines on discounted days. Incremental revenue proxy =
 *                 (lift - 1) x baseline x discounted days x average list price (sum / units).
 *  Labels         cannibalised = lift < cannibalised_lift AND give-away >= cannibalised_min_giveaway; rarely sold at full
 *                 price = more than rarely_full_share of the period's units sold discounted.
 *  Episodes       consecutive discounted days, INFERRED from receipts, not a recorded markdown: speed before (before_days),
 *                 during and after (after_days).
 *  The page cannot see the cause: pay days, chain-wide promos, price changes, adjacent stock-outs, halo or cannibalisation
 *  between items, loyalty-card discounts and price-elastic items all look the same here.
 */
class Markdowns
{
    public const COVER_SENTINEL = 1.0E9;

    /**
     * @param  array<int, int>  $shopIds  already intersected with the viewer's scope
     * @return array<string, mixed>
     */
    public function candidates(Carbon $from, Carbon $to, array $shopIds, bool $withProfit = false, ?Carbon $now = null): array
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();
        [$from, $to] = $this->window($from, $to, $today);
        $shops = $this->shops($shopIds);
        if ($from === null || $shops === []) {
            return [];
        }
        $scope = array_keys($shops);
        $cfg = (array) config('markdown.candidates');
        $cap = (float) $cfg['cover_cap_days'];

        // Pass 1: current stock of the pairs the latest sync reported; the not reported ones are only counted.
        $stockRows = Stock::applyReported(Stock::applyCurrent(DB::table('stocks')))
            ->whereIn('stocks.shop_id', $scope)->where('stocks.qty', '>', 0)
            ->get(['stocks.item_id', 'stocks.shop_id', 'stocks.qty']);
        $notReported = (int) Stock::applyNotReported(Stock::applyCurrent(DB::table('stocks')))
            ->whereIn('stocks.shop_id', $scope)->where('stocks.qty', '>', 0)->count();
        $latest = Stock::latestDates($scope);
        $asOf = $latest === [] ? null : max($latest);

        $kpi = [
            'candidates' => 0, 'units' => 0.0, 'value' => 0.0, 'no_price' => 0, 'delist' => 0,
            'not_reported' => $notReported, 'small_stock' => 0,
            'excluded' => ['young_shop' => 0, 'new_item' => 0, 'open_order' => 0],
            'stock_as_of' => $asOf,
            'stock_stale' => $asOf !== null && Carbon::parse($asOf)->lt($today->copy()->subDays(\App\Services\UnsoldStock::STALE_STOCK_DAYS)),
            'truncated' => false, 'value_cost' => null, 'without_cost' => 0,
        ];
        $base = [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => (int) $from->diffInDays($to) + 1],
            'as_of' => $now->format('Y-m-d H:i'),
            'kpi' => $kpi, 'rows' => [], 'delist' => [], 'groups' => [], 'shops' => array_values($shops),
            'seasonal_known' => false, 'profit' => $withProfit,
        ];
        if ($stockRows->isEmpty()) {
            return $base;
        }

        // Pass 2: shop history and open orders of the same shop and item.
        $shopFirst = $this->shopFirstSeen($scope);
        $receiptStart = $shopFirst['receipts'] === [] ? null : min($shopFirst['receipts']);
        $openOrders = [];
        foreach (InTransit::apply(DB::table('order_items as ol')->join('orders as o', 'o.id', '=', 'ol.order_id'))
            ->whereIn('o.shop_id', $scope)->groupBy('o.shop_id', 'ol.item_id')
            ->selectRaw('o.shop_id AS shop_id, ol.item_id AS item_id')->get() as $o) {
            $openOrders[$o->item_id.':'.$o->shop_id] = true;
        }
        $delistRules = [];
        foreach (DB::table('item_order_rules')->whereIn('shop_id', $scope)->where('min', '<=', 0)->where('max', '<=', 0)->get(['item_id', 'shop_id']) as $r) {
            $delistRules[$r->item_id.':'.$r->shop_id] = true;
        }

        $pairs = [];
        $kept = [];
        foreach ($stockRows as $s) {
            $key = $s->item_id.':'.$s->shop_id;
            $shopDays = $shopFirst['days'][(int) $s->shop_id] ?? 0;
            if ($shopDays < (int) $cfg['min_shop_history_days']) {
                $kpi['excluded']['young_shop']++;

                continue;
            }
            if (isset($openOrders[$key])) {
                $kpi['excluded']['open_order']++;

                continue;
            }
            $pairs[] = [(int) $s->item_id, (int) $s->shop_id, (float) $s->qty];
            $kept[(int) $s->item_id] = true;
        }
        $itemIds = array_keys($kept);

        // Pass 3: item age, sell and cost prices, sale evidence, forecast speeds.
        $itemFirst = $this->itemFirstSeen($itemIds);
        $prices = $this->sellPrices($itemIds);
        $costs = $withProfit ? $this->costPrices($itemIds) : [];
        $sold = app(UnsoldStock::class)->soldPairs($from->copy()->startOfDay(), $to->copy()->endOfDay(), $scope);
        $forecast = $this->forecastRows($scope, $now);
        $transferIndex = $this->transferIndex($forecast, (int) $cfg['transfer_horizon_days']);

        $fresh = array_map('intval', (array) $cfg['fresh_group_ids']);
        $meta = app(UnsoldStock::class)->itemMeta($itemIds);

        $rows = [];
        $delist = [];
        $noSalePairs = [];
        foreach ($pairs as [$item, $shop, $qty]) {
            $key = $item.':'.$shop;
            $age = $itemFirst[$item] ?? null;
            if ($age !== null && $age < (int) $cfg['min_item_age_days']) {
                $kpi['excluded']['new_item']++;

                continue;
            }
            $price = $prices[$item] ?? null;
            $value = $price === null ? null : $qty * $price;

            if (isset($delistRules[$key])) {
                $delist[] = ['item_id' => $item, 'shop_id' => $shop, 'on_hand' => $qty, 'value' => $value, 'no_sale' => ! isset($sold[$key])];

                continue;
            }

            $f = $forecast[$key] ?? null;
            $speed = null;
            $lowConfidence = false;
            if ($f !== null) {
                $lowConfidence = $f['confidence'] === 'low';
                if ($f['speed'] !== null && $f['confidence'] !== 'none') {
                    if ($f['speed'] > 0) {
                        $speed = (float) $f['speed'];
                    } elseif (in_array($f['confidence'], ['ok', 'high'], true)) {
                        $speed = 0.0;
                    }
                }
            }
            $cover = $speed === null ? null : ($speed > 0 ? $qty / $speed : self::COVER_SENTINEL);
            $noSale = ! isset($sold[$key]);

            $reasons = [];
            if ($noSale) {
                $reasons[] = 'no_sale';
            }
            if ($cover !== null && $cover > (float) $cfg['cover_days']) {
                $reasons[] = 'cover';
            }
            $overMax = $f !== null && (float) $f['max'] > 0 && $qty > (float) $cfg['over_max_multiple'] * (float) $f['max'];
            if ($overMax) {
                $reasons[] = 'over_max';
            }
            if ($reasons === []) {
                continue;
            }
            if ($qty < (int) $cfg['min_units'] || ($value !== null && $value < (float) $cfg['min_value'])) {
                $kpi['small_stock']++;

                continue;
            }

            $row = [
                'item_id' => $item, 'shop_id' => $shop, 'on_hand' => $qty,
                'price' => $price, 'value' => $value,
                'speed' => $speed, 'confidence' => $f['confidence'] ?? null,
                'cover' => $cover === null ? null : min($cover, $cap), 'cover_over_cap' => $cover !== null && $cover > $cap,
                'reasons' => $reasons, 'last_sale' => null, 'days_since' => null, 'item_age' => $age,
                'flags' => array_values(array_filter([
                    $lowConfidence ? 'low_confidence' : null,
                    $speed === null ? 'no_speed' : null,
                    $price === null ? 'no_price' : null,
                ])),
                'transfer' => $this->transferHint($item, $shop, $qty, $transferIndex, $shops),
                'depth' => null, 'depth_basis' => null,
            ];
            if ($withProfit) {
                $cost = $costs[$item] ?? null;
                $row['cost_value'] = $cost === null ? null : $qty * $cost;
            }
            if ($noSale) {
                $noSalePairs[$item][] = $shop;
            }
            $rows[$key] = $row;
        }

        // Last sale of the no-sale pairs only (a pair with a sale in the period can not be old enough for the age rung).
        $last = [];
        foreach (array_chunk(array_keys($noSalePairs), 1000) as $chunk) {
            $last += app(UnsoldStock::class)->lastSales($chunk, $scope, $to, true);
        }
        $seasonal = $this->lastYear(array_values(array_unique(array_column($rows, 'item_id'))), $scope, $from, $to);
        $seasonalKnown = $receiptStart !== null && Carbon::parse($receiptStart)->lte($from->copy()->subYear());

        foreach ($rows as $key => &$row) {
            if (in_array('no_sale', $row['reasons'], true)) {
                $date = $last[$key] ?? null;
                $row['last_sale'] = $date;
                $row['days_since'] = $date === null ? null : (int) Carbon::parse($date)->diffInDays($today);
            }
            $row = $this->applyDepth($row, $meta[$row['item_id']]['group_id'] ?? null, $fresh, $withProfit, $costs);
            if (($seasonal[$row['item_id']] ?? 0) >= (float) $cfg['seasonal_min_units']) {
                $row['flags'][] = 'seasonal';
            }
        }
        unset($row);

        // Totals are exact; the list is capped.
        foreach ($rows as $row) {
            $kpi['candidates']++;
            $kpi['units'] += $row['on_hand'];
            if ($row['value'] === null) {
                $kpi['no_price']++;
            } else {
                $kpi['value'] += $row['value'];
            }
            if ($withProfit) {
                if (($row['cost_value'] ?? null) === null) {
                    $kpi['without_cost']++;
                } else {
                    $kpi['value_cost'] = ($kpi['value_cost'] ?? 0.0) + $row['cost_value'];
                }
            }
        }
        $kpi['delist'] = count($delist);

        $max = (int) $cfg['max_rows'];
        $kpi['truncated'] = count($rows) > $max || count($delist) > $max;
        usort($rows, fn ($a, $b) => [$b['value'] ?? -1, $b['on_hand']] <=> [$a['value'] ?? -1, $a['on_hand']]);
        $rows = array_slice($rows, 0, $max);
        usort($delist, fn ($a, $b) => [$b['value'] ?? -1, $b['on_hand']] <=> [$a['value'] ?? -1, $a['on_hand']]);
        $delist = array_slice($delist, 0, $max);

        $metaAll = $meta + app(UnsoldStock::class)->itemMeta(array_values(array_diff(array_column($delist, 'item_id'), array_keys($meta))));
        $decorate = function (array $r) use ($metaAll, $shops) {
            $m = $metaAll[$r['item_id']] ?? [];
            $r['name'] = $m['name'] ?? '#'.$r['item_id'];
            $r['group_id'] = $m['group_id'] ?? null;
            $r['group'] = $m['group'] ?? null;
            $r['shop'] = (string) ($shops[$r['shop_id']] ?? '#'.$r['shop_id']);

            return $r;
        };

        $base['kpi'] = $kpi;
        $base['rows'] = array_map($decorate, $rows);
        $base['delist'] = array_map($decorate, $delist);
        $base['groups'] = collect($base['rows'])->pluck('group')->filter()->unique()->sort()->values()->all();
        $base['seasonal_known'] = $seasonalKnown;

        return $base;
    }

    /**
     * Discount effectiveness (receipt lines only), see the class docblock.
     *
     * @param  array<int, int>  $shopIds  already intersected with the viewer's scope
     * @return array<string, mixed>
     */
    public function discounts(Carbon $from, Carbon $to, array $shopIds, ?Carbon $now = null): array
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();
        [$from, $to] = $this->window($from, $to, $today);
        $shops = $this->shops($shopIds);
        if ($from === null || $shops === []) {
            return [];
        }
        $scope = array_keys($shops);
        $cfg = (array) config('markdown.effectiveness');
        $yesterday = $today->copy()->subDay();

        $readFrom = $from->copy()->subDays((int) $cfg['before_days']);
        $readTo = $to->copy()->addDays((int) $cfg['after_days'])->min($yesterday);

        $base = [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => (int) $from->diffInDays($to) + 1],
            'as_of' => $now->format('Y-m-d H:i'),
            'kpi' => ['pairs' => 0, 'analysed' => 0, 'judged' => 0, 'na' => 0, 'give_away' => 0.0, 'cannibalised' => 0, 'rarely_full' => 0, 'truncated' => false, 'silent_shop_days' => 0],
            'rows' => [],
        ];

        // Pass 1: the days that look discounted, per (shop, item, day).
        $disc = $this->discSql();
        $candidates = DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')
            ->where('r.active', true)->where('ri.storno', false)
            ->whereIn('r.shop_id', $scope)
            ->whereBetween('r.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('r.shop_id', 'ri.item_id', 'd')
            ->havingRaw("SUM(CASE WHEN r.sell = 1 AND {$disc} THEN ri.qty ELSE 0 END) > 0 AND SUM(CASE WHEN r.sell = 1 AND {$disc} THEN ri.qty ELSE 0 END) >= ".sprintf('%.4F', (float) $cfg['day_min_share']).' * SUM(CASE WHEN r.sell = 1 THEN ri.qty ELSE 0 END)')
            ->selectRaw("r.shop_id AS shop_id, ri.item_id AS item_id, DATE(r.created_at) AS d,
                COALESCE(SUM(CASE WHEN r.sell = 1 AND {$disc} THEN ri.sum - ri.total ELSE 0 END), 0) AS disc_give")
            ->get();

        $give = [];
        foreach ($candidates as $c) {
            $key = $c->shop_id.':'.$c->item_id;
            $give[$key] = ($give[$key] ?? 0.0) + (float) $c->disc_give;
        }
        $base['kpi']['pairs'] = count($give);
        if ($give === []) {
            return $base;
        }
        arsort($give);
        $maxPairs = (int) $cfg['max_pairs'];
        $base['kpi']['truncated'] = count($give) > $maxPairs;
        $give = array_slice($give, 0, $maxPairs, true);

        $byItem = [];
        foreach (array_keys($give) as $key) {
            [$shop, $item] = array_map('intval', explode(':', $key));
            $byItem[$item][$shop] = true;
        }

        // Pass 2 and 3: every day of those pairs (incl. before / after) and the in-stock days.
        $days = [];
        $stockDays = [];
        foreach (array_chunk(array_keys($byItem), 500) as $chunk) {
            $rows = DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')
                ->where('r.active', true)->where('ri.storno', false)
                ->whereIn('r.shop_id', $scope)->whereIn('ri.item_id', $chunk)
                ->whereBetween('r.created_at', [$readFrom->copy()->startOfDay(), $readTo->copy()->endOfDay()])
                ->groupBy('r.shop_id', 'ri.item_id', 'd')
                ->selectRaw("r.shop_id AS shop_id, ri.item_id AS item_id, DATE(r.created_at) AS d,
                    COALESCE(SUM(CASE WHEN r.sell = 1 THEN ri.qty ELSE 0 END), 0) AS sale_qty,
                    COALESCE(SUM(CASE WHEN r.sell = 0 THEN ABS(ri.qty) ELSE 0 END), 0) AS refund_qty,
                    COALESCE(SUM(CASE WHEN r.sell = 1 AND {$disc} THEN ri.qty ELSE 0 END), 0) AS disc_qty,
                    COALESCE(SUM(CASE WHEN r.sell = 1 AND {$disc} THEN ri.sum - ri.total ELSE 0 END), 0) AS disc_give,
                    COALESCE(SUM(CASE WHEN r.sell = 1 AND {$disc} THEN ri.sum ELSE 0 END), 0) AS disc_gross,
                    MIN(CASE WHEN r.sell = 1 AND {$disc} THEN (ri.sum - ri.total) / ri.sum END) AS depth_min,
                    MAX(CASE WHEN r.sell = 1 AND {$disc} THEN (ri.sum - ri.total) / ri.sum END) AS depth_max,
                    COALESCE(SUM(CASE WHEN r.sell = 1 THEN COALESCE(ri.sum, ri.total) ELSE 0 END), 0) AS gross")
                ->get();
            foreach ($rows as $r) {
                if (isset($give[$r->shop_id.':'.$r->item_id])) {
                    $days[$r->shop_id.':'.$r->item_id][(string) $r->d] = $r;
                }
            }
            $stock = DB::table('stocks')->whereIn('shop_id', $scope)->whereIn('item_id', $chunk)
                ->whereBetween('stock_date', [$readFrom->toDateString(), $readTo->toDateString()])
                ->where('qty', '>', 0)->get(['shop_id', 'item_id', 'stock_date']);
            foreach ($stock as $s) {
                if (isset($give[$s->shop_id.':'.$s->item_id])) {
                    $stockDays[$s->shop_id.':'.$s->item_id][substr((string) $s->stock_date, 0, 10)] = true;
                }
            }
        }

        // Pass 4: silent shop-days (missing data, not zero demand).
        $gaps = app(SalesGaps::class);
        $refFrom = $readFrom->copy()->min($readTo->copy()->subDays(27));
        $perDay = $gaps->receiptsPerDay($refFrom, $readTo, $scope, null);
        $silent = $gaps->silentDays($perDay, $refFrom, $readFrom, $readTo);
        $base['kpi']['silent_shop_days'] = array_sum(array_map('count', $silent));

        $legacy = config('inventory.refund_netting', 'same_day') === 'legacy';
        $out = [];
        foreach ($give as $key => $giveTotal) {
            [$shop, $item] = array_map('intval', explode(':', $key));
            $out[] = $this->judgePair($shop, $item, $days[$key] ?? [], $stockDays[$key] ?? [], $silent[$shop] ?? [], $from, $to, $readFrom, $readTo, $legacy, $cfg);
        }

        $meta = app(UnsoldStock::class)->itemMeta(array_values(array_unique(array_column($out, 'item_id'))));
        foreach ($out as $o) {
            $base['kpi']['analysed']++;
            $o['status'] === 'ok' ? $base['kpi']['judged']++ : $base['kpi']['na']++;
            $base['kpi']['give_away'] += $o['give_away'];
            $base['kpi']['cannibalised'] += $o['cannibalised'] ? 1 : 0;
            $base['kpi']['rarely_full'] += $o['rarely_full'] ? 1 : 0;
        }
        usort($out, fn ($a, $b) => $b['give_away'] <=> $a['give_away']);
        $out = array_slice($out, 0, (int) $cfg['max_rows']);
        foreach ($out as &$o) {
            $m = $meta[$o['item_id']] ?? [];
            $o['name'] = $m['name'] ?? '#'.$o['item_id'];
            $o['group'] = $m['group'] ?? null;
            $o['shop'] = (string) ($shops[$o['shop_id']] ?? '#'.$o['shop_id']);
        }
        unset($o);
        $base['rows'] = $out;

        return $base;
    }

    /** One pair: discounted / full-price days, weekday-weighted lift, give-away and the inferred episodes. */
    private function judgePair(int $shop, int $item, array $days, array $stockDays, array $silent, Carbon $from, Carbon $to, Carbon $readFrom, Carbon $readTo, bool $legacy, array $cfg): array
    {
        $units = function (string $date) use ($days, $legacy): float {
            $r = $days[$date] ?? null;
            if ($r === null) {
                return 0.0;
            }
            $net = (float) $r->sale_qty - (float) $r->refund_qty;

            return $legacy ? $net : max($net, 0.0);
        };
        $usable = fn (string $date): bool => isset($stockDays[$date]) && ! isset($silent[$date]);

        $dd = [];       // weekday => [units, ...] on discounted days
        $df = [];       // weekday => [units, ...] on full-price days
        $discDates = [];
        $give = 0.0;
        $gross = 0.0;
        $discGross = 0.0;
        $discUnitsOnDays = 0.0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            if (! $usable($date)) {
                continue;
            }
            $r = $days[$date] ?? null;
            $sale = $r === null ? 0.0 : (float) $r->sale_qty;
            $share = $sale > 0 ? (float) $r->disc_qty / $sale : 0.0;
            $consistent = $r !== null && $r->depth_min !== null && ((float) $r->depth_max - (float) $r->depth_min) <= (float) $cfg['depth_spread'];
            if ($sale > 0 && (float) $r->disc_qty > 0 && $share >= (float) $cfg['day_min_share'] && $consistent) {
                $dd[$d->dayOfWeek][] = $units($date);
                $discDates[] = $date;
                $give += (float) $r->disc_give;
                $discGross += (float) $r->disc_gross;
                $gross += (float) $r->gross;
                $discUnitsOnDays += $sale;
            } elseif ($share <= (float) $cfg['full_price_max_share']) {
                $df[$d->dayOfWeek][] = $units($date);
            }
        }

        // Whole-period share of discounted units (every day with sales, in stock or not).
        $soldAll = 0.0;
        $soldDisc = 0.0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (isset($days[$d->toDateString()])) {
                $soldAll += (float) $days[$d->toDateString()]->sale_qty;
                $soldDisc += (float) $days[$d->toDateString()]->disc_qty;
            }
        }
        $discShare = $soldAll > 0 ? $soldDisc / $soldAll : 0.0;

        $nDd = array_sum(array_map('count', $dd));
        $nDf = array_sum(array_map('count', $df));
        $row = [
            'shop_id' => $shop, 'item_id' => $item, 'status' => 'na', 'reason' => 'days',
            'disc_days' => $nDd, 'full_days' => $nDf, 'lift' => null, 'baseline' => null, 'disc_mean' => null,
            'depth' => $discGross > 0 ? $give / $discGross * 100 : null,
            'give_away' => $give, 'incremental' => null, 'list_price' => $discUnitsOnDays > 0 ? $gross / $discUnitsOnDays : null,
            'disc_share' => $discShare * 100, 'cannibalised' => false,
            'rarely_full' => $soldAll > 0 && $discShare > (float) $cfg['rarely_full_share'],
            'episodes' => [],
        ];

        if ($nDd >= (int) $cfg['min_discounted_days'] && $nDf >= (int) $cfg['min_full_price_days']) {
            // Keep the weekdays that have both kinds of days, weights = the weekday mix of the discounted days.
            $keptDd = array_intersect_key($dd, $df);
            $n = array_sum(array_map('count', $keptDd));
            if ($n < (int) $cfg['min_discounted_days']) {
                $row['reason'] = 'weekday';
            } else {
                $mean = fn (array $v) => array_sum($v) / count($v);
                $baseline = 0.0;
                $discSum = 0.0;
                foreach ($keptDd as $wd => $list) {
                    $baseline += count($list) / $n * $mean($df[$wd]);
                    $discSum += array_sum($list);
                }
                $discMean = $discSum / $n;
                $row['disc_mean'] = $discMean;
                $row['baseline'] = $baseline;
                if ($baseline <= 0) {
                    $row['reason'] = 'no_base';
                } else {
                    $lift = $discMean / $baseline;
                    $row['status'] = 'ok';
                    $row['reason'] = null;
                    $row['lift'] = $lift;
                    $row['incremental'] = $row['list_price'] === null ? null : ($lift - 1) * $baseline * $n * $row['list_price'];
                    $row['cannibalised'] = $lift < (float) $cfg['cannibalised_lift'] && $give >= (float) $cfg['cannibalised_min_giveaway'];
                }
            }
        }

        $row['episodes'] = $this->episodes($discDates, $units, $usable, $readFrom, $readTo, $cfg);

        return $row;
    }

    /**
     * Runs of consecutive discounted days with the speed before / during / after (inferred, not a recorded markdown).
     *
     * @param  array<int, string>  $discDates
     * @return array<int, array<string, mixed>>
     */
    private function episodes(array $discDates, \Closure $units, \Closure $usable, Carbon $readFrom, Carbon $readTo, array $cfg): array
    {
        if ($discDates === []) {
            return [];
        }
        sort($discDates);
        $runs = [];
        foreach ($discDates as $date) {
            $last = array_key_last($runs);
            if ($last !== null && Carbon::parse($runs[$last][1])->addDay()->toDateString() === $date) {
                $runs[$last][1] = $date;
            } else {
                $runs[] = [$date, $date];
            }
        }
        $speed = function (Carbon $a, Carbon $b) use ($units, $usable, $readFrom, $readTo): ?float {
            $n = 0;
            $sum = 0.0;
            for ($d = $a->copy()->max($readFrom); $d->lte($b->copy()->min($readTo)); $d->addDay()) {
                if ($usable($d->toDateString())) {
                    $n++;
                    $sum += $units($d->toDateString());
                }
            }

            return $n >= 3 ? $sum / $n : null;
        };

        $out = [];
        foreach (array_reverse($runs) as [$start, $end]) {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
            $out[] = [
                'start' => $start, 'end' => $end, 'days' => (int) $s->diffInDays($e) + 1,
                'before' => $speed($s->copy()->subDays((int) $cfg['before_days']), $s->copy()->subDay()),
                'during' => $speed($s, $e) ?? $this->plainSpeed($s, $e, $units, $usable),
                'after' => $speed($e->copy()->addDay(), $e->copy()->addDays((int) $cfg['after_days'])),
            ];
            if (count($out) >= (int) $cfg['episodes_per_pair']) {
                break;
            }
        }

        return $out;
    }

    /** Speed over an episode shorter than 3 days: shown anyway (the episode itself is the sample). */
    private function plainSpeed(Carbon $a, Carbon $b, \Closure $units, \Closure $usable): ?float
    {
        $n = 0;
        $sum = 0.0;
        for ($d = $a->copy(); $d->lte($b); $d->addDay()) {
            if ($usable($d->toDateString())) {
                $n++;
                $sum += $units($d->toDateString());
            }
        }

        return $n > 0 ? $sum / $n : null;
    }

    /** SQL test for a discounted sale line: discount (sum - totalSum) above line_min_depth of the line sum. */
    private function discSql(): string
    {
        return '(ri.sum IS NOT NULL AND ri.sum > 0 AND (ri.sum - ri.total) > '.sprintf('%.4F', (float) config('markdown.effectiveness.line_min_depth')).' * ri.sum)';
    }

    /**
     * Depth ladder: the deeper of the cover-based, last-sale-age-based and over-max rung; cost floor with ProfitAccess.
     *
     * @param  array<int, int>  $fresh
     * @param  array<int, float>  $costs
     */
    private function applyDepth(array $row, ?int $groupId, array $fresh, bool $withProfit, array $costs): array
    {
        $ladder = (array) config('markdown.ladder');

        if ($groupId !== null && in_array($groupId, $fresh, true)) {
            $row['flags'][] = 'fresh';

            return $row;
        }

        $coverDepth = 0;
        if ($row['cover'] !== null) {
            $cover = $row['cover_over_cap'] ? PHP_FLOAT_MAX : $row['cover'];
            foreach ($ladder['cover'] as $rung) {
                if ($cover >= (float) $rung['from']) {
                    $coverDepth = max($coverDepth, (int) $rung['depth']);
                }
            }
        }
        $ageDepth = 0;
        if (in_array('no_sale', $row['reasons'], true)) {
            $ageDepth = (int) $ladder['no_sale_depth'];
            $old = $row['days_since'] !== null
                ? $row['days_since'] >= (int) $ladder['old_sale_days']
                : ($row['item_age'] !== null && $row['item_age'] >= (int) $ladder['old_sale_days']);
            if ($old) {
                $ageDepth = (int) $ladder['old_sale_depth'];
            }
        }
        $overDepth = in_array('over_max', $row['reasons'], true) ? (int) $ladder['over_max_depth'] : 0;

        $depth = max($coverDepth, $ageDepth, $overDepth);
        $row['depth_basis'] = $depth === $ageDepth && $ageDepth > 0 ? 'age' : ($depth === $coverDepth && $coverDepth > 0 ? 'cover' : 'over_max');

        if ($withProfit) {
            $cost = $costs[$row['item_id']] ?? null;
            if ($cost === null || $row['price'] === null) {
                $row['flags'][] = 'cost_unknown';
            } else {
                $allowed = (1 - $cost / $row['price']) * 100;
                if ($allowed < $depth) {
                    $row['cost_limited_from'] = $depth;
                    $depth = max(0, (int) floor($allowed));
                    $row['flags'][] = 'cost_limited';
                }
            }
        }
        $row['depth'] = $depth;

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function transferHint(int $item, int $shop, float $qty, array $index, array $shops): ?array
    {
        $best = null;
        foreach ($index[$item] ?? [] as $target) {
            if ($target['shop_id'] === $shop) {
                continue;
            }
            $send = min($qty, $target['need']);
            if ($send >= 1 && ($best === null || $send > $best['qty'])) {
                $best = ['shop_id' => $target['shop_id'], 'shop' => (string) ($shops[$target['shop_id']] ?? ''), 'qty' => floor($send)];
            }
        }

        return $best;
    }

    /**
     * Shops that sell an item fast: trusted speed, cover (est / speed) under transfer_cover_days and a real order need.
     *
     * @param  array<string, array<string, mixed>>  $forecast
     * @return array<int, array<int, array{shop_id: int, need: float}>>
     */
    private function transferIndex(array $forecast, int $horizon): array
    {
        $horizon = max(1, min(7, $horizon));
        $limit = (float) config('markdown.candidates.transfer_cover_days');
        $out = [];
        foreach ($forecast as $f) {
            if ($f['speed'] === null || $f['speed'] <= 0 || ! in_array($f['confidence'], ['ok', 'high'], true)) {
                continue;
            }
            $need = (float) ($f['order'][$horizon] ?? 0);
            if ($need >= 1 && $limit > $f['est'] / $f['speed']) {
                $out[$f['item_id']][] = ['shop_id' => $f['shop_id'], 'need' => $need];
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> "item:shop" => ForecastService row */
    private function forecastRows(array $scope, Carbon $now): array
    {
        $out = [];
        foreach (app(ForecastService::class)->buildAll($scope, [], $now)['rows'] as $row) {
            $out[$row['item_id'].':'.$row['shop_id']] = $row;
        }

        return $out;
    }

    /**
     * Whole days of data per shop (earliest of first receipt and first stock row), plus the first receipt dates.
     *
     * @return array{days: array<int, int>, receipts: array<int, string>}
     */
    private function shopFirstSeen(array $scope): array
    {
        $receipts = DB::table('receipts')->whereIn('shop_id', $scope)->groupBy('shop_id')
            ->selectRaw('shop_id, MIN(created_at) AS first_at')->pluck('first_at', 'shop_id')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $stocks = DB::table('stocks')->whereIn('shop_id', $scope)->groupBy('shop_id')
            ->selectRaw('shop_id, MIN(stock_date) AS first_at')->pluck('first_at', 'shop_id')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();

        $today = now()->startOfDay();
        $days = [];
        foreach ($scope as $id) {
            $dates = array_filter([$receipts[$id] ?? null, $stocks[$id] ?? null]);
            $days[$id] = $dates === [] ? 0 : (int) Carbon::parse(min($dates))->diffInDays($today);
        }

        return ['days' => $days, 'receipts' => $receipts];
    }

    /**
     * Age in days of each item: earliest of its first receipt line and first stock row, anywhere in the chain.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, int>
     */
    private function itemFirstSeen(array $itemIds): array
    {
        $first = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $rows = DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')
                ->whereIn('ri.item_id', $chunk)->groupBy('ri.item_id')
                ->selectRaw('ri.item_id AS item_id, MIN(r.created_at) AS first_at')->get();
            foreach ($rows as $r) {
                $first[(int) $r->item_id] = substr((string) $r->first_at, 0, 10);
            }
            $rows = DB::table('stocks')->whereIn('item_id', $chunk)->groupBy('item_id')
                ->selectRaw('item_id, MIN(stock_date) AS first_at')->get();
            foreach ($rows as $r) {
                $date = substr((string) $r->first_at, 0, 10);
                if (! isset($first[(int) $r->item_id]) || $date < $first[(int) $r->item_id]) {
                    $first[(int) $r->item_id] = $date;
                }
            }
        }

        $today = now()->startOfDay();

        return array_map(fn (string $d) => (int) Carbon::parse($d)->diffInDays($today), $first);
    }

    /**
     * The lowest positive price of an item over every price type except the cost price.
     *
     * @return array<int, float>
     */
    private function sellPrices(array $itemIds): array
    {
        $out = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $rows = DB::table('item_prices')->where('price_id', '!=', (int) config('inventory.cost_price_id'))
                ->where('value', '>', 0)->whereIn('item_id', $chunk)->groupBy('item_id')
                ->selectRaw('item_id, MIN(value) AS v')->pluck('v', 'item_id');
            foreach ($rows as $id => $v) {
                $out[(int) $id] = (float) $v;
            }
        }

        return $out;
    }

    /** @return array<int, float> */
    private function costPrices(array $itemIds): array
    {
        $out = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            foreach (DB::table('item_prices')->where('price_id', (int) config('inventory.cost_price_id'))->where('value', '>', 0)->whereIn('item_id', $chunk)->pluck('value', 'item_id') as $id => $v) {
                $out[(int) $id] = (float) $v;
            }
        }

        return $out;
    }

    /**
     * Units sold in the same period last year (sale lines, selected shops).
     *
     * @return array<int, float>
     */
    private function lastYear(array $itemIds, array $scope, Carbon $from, Carbon $to): array
    {
        $out = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $rows = DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')
                ->where('r.active', true)->where('r.sell', true)->where('ri.storno', false)
                ->whereIn('r.shop_id', $scope)->whereIn('ri.item_id', $chunk)
                ->whereBetween('r.created_at', [$from->copy()->subYear()->startOfDay(), $to->copy()->subYear()->endOfDay()])
                ->groupBy('ri.item_id')->selectRaw('ri.item_id AS item_id, SUM(ri.qty) AS units')->get();
            foreach ($rows as $r) {
                $out[(int) $r->item_id] = (float) $r->units;
            }
        }

        return $out;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} whole days, `to` never later than yesterday */
    private function window(Carbon $from, Carbon $to, Carbon $today): array
    {
        $to = $to->copy()->startOfDay();
        if ($to->gt($today->copy()->subDay())) {
            $to = $today->copy()->subDay();
        }
        $from = $from->copy()->startOfDay();

        return $from->gt($to) ? [null, null] : [$from, $to];
    }

    /** @return array<int, string> shop id => name of the eligible shops among $shopIds (not disabled, not the warehouse) */
    private function shops(array $shopIds): array
    {
        $query = DB::table('shops')->where('is_disabled', false)->whereIn('id', array_map('intval', $shopIds))->orderBy('name');
        if (Warehouse::shopId() !== null) {
            $query->where('id', '!=', Warehouse::shopId());
        }

        return $query->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }
}
