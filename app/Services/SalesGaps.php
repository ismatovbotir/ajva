<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales growth > Sales gaps: where each shop sells less than the chain's own good shops, shown as four separate
 * levers. The numbers are ESTIMATES against the chain's own benchmark ("versus the chain", never "should be"); the
 * levers overlap, so they are NEVER added together. All thresholds live in config/sales_gaps.php.
 *
 * Metric dictionary
 * -----------------
 * Window         whole days from..to, `to` never later than yesterday. Receipt data is read from
 *                min(from, to - 27 days) so every day has same-weekday days to be compared with (silence test).
 * Eligible shops every shop that is not disabled and is not the main warehouse. Benchmarks use ALL eligible shops
 *                (chain-wide); only the benchmark VALUE is returned, never another shop's name or figures. The rows
 *                returned are the requested shops (the viewer's scope) only.
 * Sale receipt   active and sell. Storno lines never count. Refund = active and not sell. Receipt counts come from
 *                `receipts`, lines (basket) from `receipt_items`.
 * Silent day     a shop-day whose receipt feed was silent (inventory.feed_* rules: sale receipts < feed_silence_min_ratio x
 *                the median of the OTHER same-weekday days of the reference window, median >= feed_silence_min_median,
 *                >= feed_silence_min_weekdays such days). It is missing data, not zero demand: left out of every figure
 *                (sales, receipts, lines, peaks) and the days used; counted per shop and in a note.
 * Net sales      per day: sales - refunds of the SAME day, clamped at 0 (inventory.refund_netting 'same_day'; 'legacy' =
 *                sales - refunds). Sales/day = net sales / days used. Receipts/day = sale receipts / days used.
 *                Average check = net sales / sale receipts. Basket (UPT) = non-storno lines / sale receipts.
 * Trimmed check  the gap is judged on the TRIMMED MEAN of sale receipt totals. Cut = the largest total outside the chain's
 *                top 5 % (ReceiptAnalytics::BIG_TOP_SHARE, chain-wide over the window); receipts strictly above it are
 *                left out for the shop and for the benchmark alike, so a few huge receipts cannot move either side
 *                (ties with the cut stay). Needs >= 20 receipts in the chain for a cut, else nothing is trimmed.
 * Check lever    gap = (benchmark - trimmed check) / benchmark. Benchmark = percentile (default P75) of the trimmed checks
 *                of the shops with >= min_receipts sale receipts; < benchmark_min_shops (5) such shops = the MEDIAN
 *                (basis 'median', said on the page), < benchmark_floor_shops (2) = no benchmark.
 *                Recoverable per 30 days = recoverable_factor (0.3) x (benchmark - trimmed check) x receipts/day x 30,
 *                shown uncapped and capped at cap_share (5 %) of the shop's net sales per 30 days. Flag: gap > 8 %.
 *                A shop under min_receipts is not judged ('n/a: not enough receipts').
 * Card lever     a sale receipt has a customer when ReceiptAnalytics::CUSTOMER (aos not empty). Shown only when the chain
 *                has >= 200 card receipts, the shop >= min_receipts receipts and the chain's card average check >= the
 *                anonymous one. Target share = percentile of the shop shares (same >= 5 shops rule, else median).
 *                Uplift per 30 days = damping (0.5) x (chain card check / chain anonymous check - 1) x (target - share)
 *                x receipts per 30 days x chain anonymous check: an UPPER BOUND from a correlation, not proof.
 *                Flag: share at least min_gap_points below the target. No cashier-level conclusion.
 * Availability   ForecastService::build rows of ABC class A, level out, rule pairs (max > 0) REPORTED by the latest sync;
 *                pairs not reported are only COUNTED (no_data), never valued. Confidence none / low or < 10 usable days =
 *                'unknown' (no money). Lost per day = speed x realised price x recoverable_share (0.7); realised price =
 *                the shop's sale line total / qty over the window, else the lowest positive selling price (every price
 *                type except inventory.cost_price_id). Period total = days out in the window (stock rows of that shop
 *                and item with qty <= 0; a day without a row is unknown) x lost per day. A DAY-LEVEL estimate: stock is
 *                a daily snapshot, hourly stock-outs are invisible; only items that are out today.
 * Peak hours     per shop with >= 2 registers: an hour (weekday + hour) is a peak on a day when its sale receipts are >
 *                peak.ratio (1.5) x the median of the same weekday and hour on the OTHER days (>= 3), at least
 *                peak.min_receipts, and at most 1 distinct till rang them; the pattern must repeat on >= 3 days.
 *                Shop-local time, no conversion. A signal to review, no money.
 * Sorting        by the largest single money lever per 30 days (check capped, card damped, availability lost per day x 30).
 */
class SalesGaps
{
    /** @var array<string, mixed> */
    private array $cfg;

    public function __construct()
    {
        $this->cfg = (array) config('sales_gaps');
    }

    /**
     * @param  array<int, int>  $shopIds  the shops to show, already intersected with the viewer's scope; [] = none
     */
    public function build(Carbon $from, Carbon $to, array $shopIds, ?Carbon $now = null): array
    {
        $now ??= now();
        $to = $to->copy()->startOfDay();
        $yesterday = $now->copy()->startOfDay()->subDay();
        if ($to->gt($yesterday)) {
            $to = $yesterday;
        }
        $from = $from->copy()->startOfDay();
        if ($from->gt($to)) {
            return [];
        }

        $eligible = DB::table('shops')->where('is_disabled', false)
            ->when(Warehouse::shopId() !== null, fn ($q) => $q->where('id', '!=', Warehouse::shopId()))
            ->get(['id', 'name', 'area_m2'])->keyBy('id');
        $eligibleIds = $eligible->keys()->map(fn ($i) => (int) $i)->all();
        $scope = array_values(array_intersect(array_map('intval', $shopIds), $eligibleIds));
        if ($scope === []) {
            return [];
        }

        $days = (int) $from->diffInDays($to) + 1;
        $refFrom = $from->copy()->min($to->copy()->subDays(27));

        // Pass 1: the chain's big-receipt cut. Pass 2: receipts per shop and day. Pass 3: lines per shop and day.
        $cut = $this->bigCut($from, $to, $eligibleIds);
        $perDay = $this->receiptsPerDay($refFrom, $to, $eligibleIds, $cut);
        $silent = $this->silentDays($perDay, $refFrom, $from, $to);
        $agg = $this->aggregate($perDay, $silent, $from, $to);
        $lines = $this->lines($from, $to, $scope);

        $check = $this->checkBenchmark($agg);
        $card = $this->cardBenchmark($agg);

        $avail = $this->availability($scope, $from, $to, $now);
        $peaks = $this->peaks($scope, $from, $to, $silent);

        $rows = [];
        foreach ($scope as $id) {
            $rows[] = $this->shopRow(
                $eligible[$id], $agg[$id] ?? null, $silent[$id] ?? [], $lines[$id] ?? [], $check, $card,
                $avail['shops'][$id] ?? $this->emptyAvailability(), $peaks[$id] ?? []
            );
        }
        usort($rows, fn ($a, $b) => [$b['lever'], $a['name']] <=> [$a['lever'], $b['name']]);

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'as_of' => $now->format('Y-m-d H:i'),
            'stock_as_of' => $avail['stock_as_of'],
            'benchmarks' => ['check' => $check['public'], 'card' => $card['public'], 'cut' => $cut],
            'kpi' => [
                'shops' => count($rows),
                'flagged' => count(array_filter($rows, fn ($r) => $r['flags'] !== [])),
                'receipts' => array_sum(array_column($rows, 'receipts')),
                'silent_shop_days' => array_sum(array_column($rows, 'silent_days')),
                'check_benchmark_shops' => $check['public']['shops'],
                'card_benchmark_shops' => $card['public']['shops'],
                'has_area' => collect($rows)->contains(fn ($r) => $r['area_m2'] !== null),
            ],
            'shops' => $rows,
        ];
    }

    private function sales($query)
    {
        return $query->where('receipts.active', true)->where('receipts.sell', true);
    }

    /** The chain's top-5 % cut of sale receipt totals in the window (null = fewer than 20 receipts). */
    private function bigCut(Carbon $from, Carbon $to, array $shopIds): ?float
    {
        $base = fn () => $this->sales(DB::table('receipts'))
            ->whereIn('receipts.shop_id', $shopIds)
            ->whereBetween('receipts.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        $count = (int) $base()->count();
        if ($count < 20) {
            return null;
        }
        // The largest total OUTSIDE the top 5 %: only receipts strictly above it are trimmed, so ties never wipe out a shop.
        $offset = (int) floor($count * ReceiptAnalytics::BIG_TOP_SHARE);
        $cut = $base()->orderByDesc('receipts.total')->offset($offset)->limit(1)->value('receipts.total');

        return $cut === null || (float) $cut <= 0 ? null : (float) $cut;
    }

    /**
     * Sale receipts, refunds and card receipts per shop and day (one grouped query).
     *
     * @return array<int, array<string, array<string, float|int>>> shop id => date => figures
     */
    public function receiptsPerDay(Carbon $from, Carbon $to, array $shopIds, ?float $cut): array
    {
        $customer = ReceiptAnalytics::CUSTOMER;
        $limit = $cut ?? 1e15;

        $rows = DB::table('receipts')
            ->whereIn('receipts.shop_id', $shopIds)
            ->whereBetween('receipts.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('receipts.shop_id', 'd')
            ->selectRaw(
                'receipts.shop_id as shop_id, DATE(receipts.created_at) as d,
                 SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 THEN 1 ELSE 0 END) as sale_cnt,
                 COALESCE(SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 THEN receipts.total ELSE 0 END), 0) as sale_sum,
                 COALESCE(SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 0 THEN ABS(receipts.total) ELSE 0 END), 0) as refund_sum,
                 SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND receipts.total <= ? THEN 1 ELSE 0 END) as trim_cnt,
                 COALESCE(SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND receipts.total <= ? THEN receipts.total ELSE 0 END), 0) as trim_sum,
                 SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND '.$customer.' THEN 1 ELSE 0 END) as card_cnt,
                 COALESCE(SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND '.$customer.' THEN receipts.total ELSE 0 END), 0) as card_sum',
                [$limit, $limit]
            )->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->shop_id][(string) $r->d] = [
                'sale_cnt' => (int) $r->sale_cnt, 'sale_sum' => (float) $r->sale_sum, 'refund_sum' => (float) $r->refund_sum,
                'trim_cnt' => (int) $r->trim_cnt, 'trim_sum' => (float) $r->trim_sum,
                'card_cnt' => (int) $r->card_cnt, 'card_sum' => (float) $r->card_sum,
            ];
        }

        return $out;
    }

    /**
     * Silent shop-days of the window (see the class docblock).
     *
     * @return array<int, array<string, true>> shop id => date => true
     */
    public function silentDays(array $perDay, Carbon $refFrom, Carbon $from, Carbon $to): array
    {
        if (! config('inventory.feed_aware_censoring', true)) {
            return [];
        }
        $ratio = (float) config('inventory.feed_silence_min_ratio', 0.2);
        $minMedian = (int) config('inventory.feed_silence_min_median', 20);
        $minDays = max(1, (int) config('inventory.feed_silence_min_weekdays', 3));

        $out = [];
        foreach ($perDay as $shopId => $byDate) {
            $byWd = [];
            for ($d = $refFrom->copy(); $d->lte($to); $d->addDay()) {
                $byWd[$d->dayOfWeek][$d->toDateString()] = $byDate[$d->toDateString()]['sale_cnt'] ?? 0;
            }
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $date = $d->toDateString();
                $others = array_values(array_diff_key($byWd[$d->dayOfWeek], [$date => true]));
                if (count($others) < $minDays) {
                    continue;
                }
                $median = $this->percentile(array_map('floatval', $others), 50);
                if ($median >= $minMedian && ($byDate[$date]['sale_cnt'] ?? 0) < $ratio * $median) {
                    $out[$shopId][$date] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Window totals per shop over the days that are not silent.
     *
     * @return array<int, array<string, float|int>>
     */
    private function aggregate(array $perDay, array $silent, Carbon $from, Carbon $to): array
    {
        $fromS = $from->toDateString();
        $toS = $to->toDateString();
        $legacy = config('inventory.refund_netting', 'same_day') === 'legacy';
        $totalDays = (int) $from->diffInDays($to) + 1;

        $out = [];
        foreach ($perDay as $shopId => $byDate) {
            $a = ['n' => 0, 'net' => 0.0, 'gross' => 0.0, 'trim_cnt' => 0, 'trim_sum' => 0.0, 'card_cnt' => 0, 'card_sum' => 0.0,
                'silent' => 0, 'days_used' => 0];
            foreach ($byDate as $date => $r) {
                if ($date < $fromS || $date > $toS) {
                    continue;
                }
                if (isset($silent[$shopId][$date])) {
                    continue;
                }
                $net = $r['sale_sum'] - $r['refund_sum'];
                $a['n'] += $r['sale_cnt'];
                $a['net'] += $legacy ? $net : max($net, 0.0);
                $a['gross'] += $r['sale_sum'];
                $a['trim_cnt'] += $r['trim_cnt'];
                $a['trim_sum'] += $r['trim_sum'];
                $a['card_cnt'] += $r['card_cnt'];
                $a['card_sum'] += $r['card_sum'];
            }
            // A silent day may have no receipt row at all, so it is counted from the silent list, not from the rows.
            $a['silent'] = count($silent[$shopId] ?? []);
            $a['days_used'] = $totalDays - $a['silent'];
            $out[$shopId] = $a;
        }

        return $out;
    }

    /** @return array<int, array<string, int>> shop id => date => non-storno lines of the sale receipts */
    private function lines(Carbon $from, Carbon $to, array $scope): array
    {
        $rows = $this->sales(DB::table('receipts'))
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false)
            ->whereIn('receipts.shop_id', $scope)
            ->whereBetween('receipts.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('receipts.shop_id', 'd')
            ->selectRaw('receipts.shop_id as shop_id, DATE(receipts.created_at) as d, COUNT(receipt_items.id) as line_cnt')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->shop_id][(string) $r->d] = (int) $r->line_cnt;
        }

        return $out;
    }

    /** Percentile / median of the qualifying shops, the 5-shops rule and the basis label. */
    private function benchmark(array $values): array
    {
        $n = count($values);
        if ($n < (int) $this->cfg['benchmark_floor_shops']) {
            return ['value' => null, 'basis' => 'none', 'shops' => $n];
        }
        $configured = (float) $this->cfg['benchmark_percentile'];
        $enough = $n >= (int) $this->cfg['benchmark_min_shops'];
        $p = $enough ? $configured : 50.0;

        return ['value' => $this->percentile($values, $p), 'basis' => $enough ? 'p'.(int) $configured : 'median', 'shops' => $n];
    }

    private function checkBenchmark(array $agg): array
    {
        $min = (int) $this->cfg['min_receipts'];
        $values = [];
        foreach ($agg as $a) {
            if ($a['n'] >= $min && $a['trim_cnt'] > 0) {
                $values[] = $a['trim_sum'] / $a['trim_cnt'];
            }
        }
        $b = $this->benchmark($values);

        return ['value' => $b['value'], 'public' => $b];
    }

    private function cardBenchmark(array $agg): array
    {
        $min = (int) $this->cfg['min_receipts'];
        $chainN = array_sum(array_column($agg, 'n'));
        $cardN = array_sum(array_column($agg, 'card_cnt'));
        $cardSum = array_sum(array_column($agg, 'card_sum'));
        $anonN = $chainN - $cardN;
        $anonSum = array_sum(array_column($agg, 'gross')) - $cardSum;

        $cardCheck = $cardN > 0 ? $cardSum / $cardN : null;
        $anonCheck = $anonN > 0 ? $anonSum / $anonN : null;

        $reason = null;
        if ($cardN < (int) $this->cfg['card']['min_chain_card_receipts']) {
            $reason = 'chain_card_receipts';
        } elseif ($cardCheck === null || $anonCheck === null || $anonCheck <= 0 || $cardCheck < $anonCheck) {
            $reason = 'card_check_not_higher';
        }

        $shares = [];
        foreach ($agg as $a) {
            if ($a['n'] >= $min) {
                $shares[] = $a['card_cnt'] / $a['n'];
            }
        }
        $b = $reason === null ? $this->benchmark($shares) : ['value' => null, 'basis' => 'none', 'shops' => count($shares)];

        return [
            'value' => $b['value'], 'reason' => $reason, 'card_check' => $cardCheck, 'anon_check' => $anonCheck,
            'public' => $b + ['reason' => $reason, 'card_receipts' => $cardN, 'card_check' => $cardCheck, 'anon_check' => $anonCheck],
        ];
    }

    private function emptyAvailability(): array
    {
        return ['out_a' => 0, 'valued' => 0, 'unknown' => 0, 'no_price' => 0, 'not_reported' => 0,
            'lost_per_day' => 0.0, 'lost_period' => 0.0, 'items' => []];
    }

    /**
     * Class A items that are out of stock now (ForecastService) and what they cost per day / in the window.
     *
     * @return array{shops: array<int, array<string, mixed>>, stock_as_of: ?string}
     */
    private function availability(array $scope, Carbon $from, Carbon $to, Carbon $now): array
    {
        $forecast = app(ForecastService::class)->build($scope, array_values(array_filter([Warehouse::shopId()])), $now);
        $shops = [];
        foreach ($scope as $id) {
            $shops[$id] = $this->emptyAvailability();
            $shops[$id]['not_reported'] = (int) ($forecast['no_data']['by_shop'][$id] ?? 0);
        }

        $pairs = array_values(array_filter($forecast['rows'], fn ($r) => $r['abc'] === 'A' && $r['level'] === 'out' && ! $r['no_data']));
        $latest = Stock::latestDates($scope);
        $stockAsOf = $latest === [] ? null : max($latest);
        if ($pairs === []) {
            return ['shops' => $shops, 'stock_as_of' => $stockAsOf];
        }

        $shopIds = array_values(array_unique(array_column($pairs, 'shop_id')));
        $itemIds = array_values(array_unique(array_column($pairs, 'item_id')));

        $realised = $this->sales(DB::table('receipts'))
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false)->where('receipt_items.qty', '>', 0)
            ->whereIn('receipts.shop_id', $shopIds)->whereIn('receipt_items.item_id', $itemIds)
            ->whereBetween('receipts.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('receipts.shop_id', 'receipt_items.item_id')
            ->selectRaw('receipts.shop_id as shop_id, receipt_items.item_id as item_id, SUM(receipt_items.total) as rev, SUM(receipt_items.qty) as sold_qty')
            ->get()->mapWithKeys(fn ($r) => [$r->shop_id.'|'.$r->item_id => (float) $r->sold_qty > 0 ? (float) $r->rev / (float) $r->sold_qty : 0.0]);

        $listPrice = DB::table('item_prices')
            ->where('price_id', '!=', (int) config('inventory.cost_price_id'))
            ->where('value', '>', 0)->whereIn('item_id', $itemIds)
            ->groupBy('item_id')->selectRaw('item_id, MIN(value) as v')->pluck('v', 'item_id');

        $daysOut = DB::table('stocks')
            ->whereIn('shop_id', $shopIds)->whereIn('item_id', $itemIds)
            ->where('qty', '<=', 0)->whereBetween('stock_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('shop_id', 'item_id')->selectRaw('shop_id, item_id, COUNT(*) as days_out')
            ->get()->mapWithKeys(fn ($r) => [$r->shop_id.'|'.$r->item_id => (int) $r->days_out]);

        $share = (float) $this->cfg['availability']['recoverable_share'];
        $minUsable = (int) $this->cfg['availability']['min_usable_days'];

        foreach ($pairs as $p) {
            $key = $p['shop_id'].'|'.$p['item_id'];
            $shops[$p['shop_id']]['out_a']++;

            $item = ['item_id' => $p['item_id'], 'name' => $p['item'], 'state' => 'unknown', 'speed' => $p['speed'], 'confidence' => $p['confidence'],
                'price' => null, 'per_day' => 0.0, 'days_out' => (int) ($daysOut[$key] ?? 0), 'period' => 0.0];

            if (! in_array($p['confidence'], ['ok', 'high'], true) || $p['usable_days'] < $minUsable || ! $p['speed']) {
                $shops[$p['shop_id']]['unknown']++;
            } else {
                $price = (float) ($realised[$key] ?? 0) > 0 ? (float) $realised[$key] : (float) ($listPrice[$p['item_id']] ?? 0);
                if ($price <= 0) {
                    $item['state'] = 'no_price';
                    $shops[$p['shop_id']]['no_price']++;
                } else {
                    $item['state'] = 'valued';
                    $item['price'] = $price;
                    $item['per_day'] = $p['speed'] * $price * $share;
                    $item['period'] = $item['days_out'] * $item['per_day'];
                    $shops[$p['shop_id']]['valued']++;
                    $shops[$p['shop_id']]['lost_per_day'] += $item['per_day'];
                    $shops[$p['shop_id']]['lost_period'] += $item['period'];
                }
            }
            $shops[$p['shop_id']]['items'][] = $item;
        }

        $top = (int) $this->cfg['availability']['top_items'];
        foreach ($shops as $id => $s) {
            usort($s['items'], fn ($a, $b) => [$b['state'] === 'valued', $b['per_day'], $a['name']] <=> [$a['state'] === 'valued', $a['per_day'], $b['name']]);
            $shops[$id]['items'] = array_slice($s['items'], 0, $top);
        }

        return ['shops' => $shops, 'stock_as_of' => $stockAsOf];
    }

    /**
     * Peak-hour signals per shop (shops with >= 2 registers; one grouped query).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function peaks(array $scope, Carbon $from, Carbon $to, array $silent): array
    {
        $cfg = $this->cfg['peak'];
        $shops = DB::table('pos')->whereIn('shop_id', $scope)->groupBy('shop_id')
            ->havingRaw('COUNT(*) >= ?', [(int) $cfg['min_registers']])->pluck('shop_id')->map(fn ($i) => (int) $i)->all();
        if ($shops === []) {
            return [];
        }

        $hour = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', receipts.created_at) AS INTEGER)"
            : 'HOUR(receipts.created_at)';

        $rows = $this->sales(DB::table('receipts'))
            ->whereIn('receipts.shop_id', $shops)
            ->whereBetween('receipts.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('receipts.shop_id', 'd', 'h')
            ->selectRaw("receipts.shop_id as shop_id, DATE(receipts.created_at) as d, {$hour} as h, COUNT(*) as cnt, COUNT(DISTINCT receipts.pos_id) as tills")
            ->get();

        $cells = [];
        foreach ($rows as $r) {
            $cells[(int) $r->shop_id][(int) $r->h][(string) $r->d] = [(int) $r->cnt, (int) $r->tills];
        }

        $out = [];
        foreach ($cells as $shopId => $byHour) {
            // Usable dates of the shop by weekday (silent days left out).
            $dates = [];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                if (! isset($silent[$shopId][$d->toDateString()])) {
                    $dates[$d->dayOfWeek][] = $d->toDateString();
                }
            }
            foreach ($byHour as $h => $byDate) {
                foreach ($dates as $wd => $list) {
                    $peakDays = [];
                    foreach ($list as $date) {
                        [$cnt, $tills] = $byDate[$date] ?? [0, 0];
                        if ($cnt < (int) $cfg['min_receipts'] || $tills > (int) $cfg['max_active_registers']) {
                            continue;
                        }
                        $others = [];
                        foreach ($list as $o) {
                            if ($o !== $date) {
                                $others[] = (float) ($byDate[$o][0] ?? 0);
                            }
                        }
                        if (count($others) < (int) $cfg['min_other_days']) {
                            continue;
                        }
                        $median = $this->percentile($others, 50);
                        if ($cnt > (float) $cfg['ratio'] * $median) {
                            $peakDays[] = ['date' => $date, 'cnt' => $cnt, 'median' => $median];
                        }
                    }
                    if (count($peakDays) >= (int) $cfg['min_days']) {
                        $out[$shopId][] = [
                            'weekday' => (int) $wd, 'hour' => (int) $h, 'days' => count($peakDays),
                            'avg_receipts' => array_sum(array_column($peakDays, 'cnt')) / count($peakDays),
                            'median' => array_sum(array_column($peakDays, 'median')) / count($peakDays),
                            'example_date' => end($peakDays)['date'],
                        ];
                    }
                }
            }
            if (isset($out[$shopId])) {
                usort($out[$shopId], fn ($a, $b) => [$b['days'], $b['avg_receipts']] <=> [$a['days'], $a['avg_receipts']]);
                $out[$shopId] = array_slice($out[$shopId], 0, 3);
            }
        }

        return $out;
    }

    /** One shop's row: figures, the three money levers, the peak signal and the top action. */
    private function shopRow(object $shop, ?array $a, array $silentDays, array $lineDays, array $check, array $card, array $avail, array $peaks): array
    {
        $month = (int) $this->cfg['month_days'];
        $min = (int) $this->cfg['min_receipts'];
        $area = $shop->area_m2 !== null && (float) $shop->area_m2 > 0 ? (float) $shop->area_m2 : null;

        $n = (int) ($a['n'] ?? 0);
        $daysUsed = (int) ($a['days_used'] ?? 0);
        $perDay = $daysUsed > 0 ? $n / $daysUsed : null;
        $salesDay = $daysUsed > 0 ? $a['net'] / $daysUsed : null;

        $lines = 0;
        foreach ($lineDays as $date => $c) {
            if (! isset($silentDays[$date])) {
                $lines += $c;
            }
        }

        $row = [
            'id' => (int) $shop->id, 'name' => (string) $shop->name, 'area_m2' => $area,
            'days_used' => $daysUsed, 'silent_days' => (int) ($a['silent'] ?? 0),
            'receipts' => $n, 'receipts_per_day' => $perDay, 'sales_per_day' => $salesDay,
            'avg_check' => $n > 0 ? $a['net'] / $n : null,
            'basket' => $n > 0 ? $lines / $n : null,
            'sales_per_m2' => $area !== null && $salesDay !== null ? $salesDay * $month / $area : null,
            'receipts_per_m2' => $area !== null && $perDay !== null ? $perDay * $month / $area : null,
            'min_receipts' => $min,
        ];

        // Check lever.
        $c = ['status' => 'na', 'reason' => 'receipts', 'flag' => false, 'recoverable' => null, 'recoverable_capped' => null,
            'cap' => null, 'gap_pct' => null, 'trim_check' => null];
        if ($n >= $min && ($a['trim_cnt'] ?? 0) > 0) {
            $trim = $a['trim_sum'] / $a['trim_cnt'];
            $c['trim_check'] = $trim;
            if ($check['value'] === null) {
                $c['reason'] = 'benchmark';
            } else {
                $bench = (float) $check['value'];
                $gap = $bench > 0 ? ($bench - $trim) / $bench * 100 : 0.0;
                $raw = max(0.0, (float) $this->cfg['check']['recoverable_factor'] * ($bench - $trim) * $perDay * $month);
                $cap = (float) $this->cfg['check']['cap_share'] * $salesDay * $month;
                $c = array_merge($c, [
                    'status' => 'ok', 'reason' => null, 'gap_pct' => $gap, 'flag' => $gap > (float) $this->cfg['check']['flag_gap_pct'],
                    'recoverable' => $raw, 'recoverable_capped' => min($raw, $cap), 'cap' => $cap,
                ]);
            }
        }
        $row['check'] = $c + ['benchmark' => $check['value'], 'basis' => $check['public']['basis']];

        // Card lever.
        $k = ['status' => 'na', 'reason' => $n < $min ? 'receipts' : ($card['reason'] ?? 'benchmark'), 'share' => null, 'target' => null,
            'flag' => false, 'uplift' => null];
        if ($n >= $min && $card['reason'] === null && $card['value'] !== null) {
            $share = $a['card_cnt'] / $n;
            $target = (float) $card['value'];
            $uplift = 0.0;
            if ($target > $share) {
                $uplift = (float) $this->cfg['card']['damping'] * ($card['card_check'] / $card['anon_check'] - 1)
                    * ($target - $share) * $perDay * $month * $card['anon_check'];
            }
            $k = ['status' => 'ok', 'reason' => null, 'share' => $share * 100, 'target' => $target * 100,
                'flag' => ($target - $share) * 100 >= (float) $this->cfg['card']['min_gap_points'], 'uplift' => max($uplift, 0.0)];
        }
        $row['card'] = $k;

        $row['availability'] = $avail;
        $row['peaks'] = $peaks;

        $row['flags'] = array_values(array_filter([
            $c['flag'] ? 'check' : null,
            $k['flag'] ? 'card' : null,
            $avail['out_a'] > 0 ? 'availability' : null,
            $peaks !== [] ? 'peak' : null,
        ]));

        // Largest single money lever per 30 days (never a sum).
        $levers = array_filter([
            'check' => $c['flag'] ? $c['recoverable_capped'] : null,
            'card' => $k['flag'] ? $k['uplift'] : null,
            'availability' => $avail['lost_per_day'] > 0 ? $avail['lost_per_day'] * $month : null,
        ], fn ($v) => $v !== null && $v > 0);
        arsort($levers);
        $row['lever'] = $levers === [] ? 0.0 : (float) reset($levers);
        $row['top_action'] = $levers !== [] ? array_key_first($levers) : ($peaks !== [] ? 'peak' : null);
        $row['availability_per_month'] = $avail['lost_per_day'] * $month;

        return $row;
    }

    /** @param  array<int, float>  $values */
    private function percentile(array $values, float $p): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $values[0];
        }
        $rank = $p / 100 * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);

        return (float) ($values[$lo] + ($values[$hi] - $values[$lo]) * ($rank - $lo));
    }
}
