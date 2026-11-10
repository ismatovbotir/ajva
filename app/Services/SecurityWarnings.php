<?php

namespace App\Services;

use App\Support\ReceiptChecks;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Security warnings raised from receipt data (Security > Security warnings). Every warning is a SIGNAL TO REVIEW, never
 * proof: it always carries its evidence (counts, the rate, the rest-of-chain baseline, the sample size and sample receipts).
 * Thresholds and the severity rules are in config/security.php (documented there).
 *
 * Definitions (same as ReceiptAnalytics / ReceiptsMetrics): sale = active & sell, refund = active & !sell, cancelled = !active.
 * Receipt counts come from `receipts` (cancelled receipts often have no lines). Storno lines are left out of item checks.
 * A rate is compared with the REST of the chain (all other cashiers / tills in the same period and shops) and judged only
 * above a minimum sample. No cost or profit figure is read here.
 *
 * Warning types (row `type`):
 *   refund_abuse        refund share of sales (amount, count) of a cashier / till against the rest of the chain
 *   refund_repeat       the same item refunded again and again by one cashier
 *   refund_unmatched    refund lines of an item that was not sold in that shop before (look-back window)
 *   cancelled_rate      cancelled share of all receipts
 *   cancelled_empty     cancelled receipts WITHOUT lines (counted separately: the feed often has none)
 *   cancelled_paid      cancelled receipts that carry a recorded payment
 *   discount_deep       sale receipts with a discount depth >= security.deep_discount_pct, clustered per cashier
 *   discount_late       the same deep discounts in the last minutes before the shop's last sale of the day
 *   off_hours           sale receipts in hours with < 1 % of the shop's own volume (28 days)
 *   number_gap          missing receipt numbers inside a till shift (session)
 *   duplicate_receipt   same till + number + total in different shifts within a minute
 *   silent_shop         usual opening hours without any sale, against the same weekday median
 *   silent_till         tills registered but never used
 *   data_*              sale total <= 0, qty <= 0 lines, negative discount, future dates, receipt shop != till shop
 *   unusual_basket      one line with a large qty and a total far above the shop's big-receipt cut
 *
 *   data_late         till receipts that arrived more than receipts.late_hours after their own time (receipts.ingested_at,
 *                     first insert only; old rows without it and 1C history deliveries are not checked)
 *   data_flags        receipts accepted with a data warning (receipts.dq_flags: neg_qty, qty_gt_max, old_date; the flags
 *                     bad_total / sum_lt_total are covered by data_total / data_discount)
 *
 * Skipped on purpose (see notes()): "cancelled shortly after payment" (payment rows carry insert time, not till time, so the
 * check is "a payment is recorded on a cancelled receipt").
 */
class SecurityWarnings
{
    private const SEVERITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2];

    private const MAX_ROWS = 300;

    private const FIELDS = ['sale_count', 'sale_sum', 'refund_count', 'refund_sum', 'cancelled_count', 'all_count', 'deep_count', 'empty_count', 'paid_count'];

    /** Rate metrics: numerator, denominator, events behind the rate, sample, warning type, event kind for sample receipts. */
    private const RATES = [
        'refund_amount' => ['num' => 'refund_sum', 'den' => 'sale_sum', 'events' => 'refund_count', 'sample' => 'all_count', 'type' => 'refund_abuse', 'kind' => 'refund'],
        'refund_count' => ['num' => 'refund_count', 'den' => 'txn_count', 'events' => 'refund_count', 'sample' => 'all_count', 'type' => 'refund_abuse', 'kind' => 'refund'],
        'cancelled' => ['num' => 'cancelled_count', 'den' => 'all_count', 'events' => 'cancelled_count', 'sample' => 'all_count', 'type' => 'cancelled_rate', 'kind' => 'cancelled'],
        'cancelled_empty' => ['num' => 'empty_count', 'den' => 'all_count', 'events' => 'empty_count', 'sample' => 'all_count', 'type' => 'cancelled_empty', 'kind' => 'cancelled_empty'],
        'cancelled_paid' => ['num' => 'paid_count', 'den' => 'all_count', 'events' => 'paid_count', 'sample' => 'all_count', 'type' => 'cancelled_paid', 'kind' => 'cancelled_paid'],
        'discount_deep' => ['num' => 'deep_count', 'den' => 'sale_count', 'events' => 'deep_count', 'sample' => 'sale_count', 'type' => 'discount_deep', 'kind' => 'deep'],
    ];

    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * @param  array<int, int>  $shopIds  already intersected with the viewer's scope; [] = none
     * @return array{warnings: array<int, array<string, mixed>>, kpi: array<string, int>, stamp: array<string, mixed>, notes: array<int, array<string, string>>, truncated: bool}
     */
    public function build(Carbon $from, Carbon $to, array $shopIds, ?Carbon $now = null): array
    {
        $now ??= now();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $this->memo = [];

        $stamp = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'receipts' => 0, 'generated_at' => $now->format('Y-m-d H:i')];

        if ($shopIds === []) {
            return ['warnings' => [], 'kpi' => $this->kpi([]), 'stamp' => $stamp, 'notes' => $this->notes(), 'truncated' => false];
        }

        $stamp['receipts'] = (int) $this->base($from, $to, $shopIds)->count();

        $rows = array_merge(
            $this->refunds($from, $to, $shopIds),
            $this->repeatedRefunds($from, $to, $shopIds),
            $this->unmatchedRefunds($from, $to, $shopIds),
            $this->cancelled($from, $to, $shopIds),
            $this->discounts($from, $to, $shopIds),
            $this->lateDiscounts($from, $to, $shopIds),
            $this->offHours($from, $to, $shopIds),
            $this->numberGaps($from, $to, $shopIds),
            $this->duplicates($from, $to, $shopIds),
            $this->silentShops($from, $to, $shopIds, $now),
            $this->idleTills($from, $to, $shopIds),
            $this->dataIntegrity($from, $to, $shopIds, $now),
            $this->unusualBaskets($from, $to, $shopIds),
        );

        usort($rows, fn ($a, $b) => [self::SEVERITY_RANK[$a['severity']], $a['type'], -$a['value']] <=> [self::SEVERITY_RANK[$b['severity']], $b['type'], -$b['value']]);

        $truncated = count($rows) > self::MAX_ROWS;

        return [
            'warnings' => array_slice($rows, 0, self::MAX_ROWS),
            'kpi' => $this->kpi($rows),
            'stamp' => $stamp,
            'notes' => $this->notes(),
            'truncated' => $truncated,
        ];
    }

    /** Warning type => label (English source, translate with __()). */
    public static function typeLabels(): array
    {
        return [
            'refund_abuse' => 'Refunds above the chain',
            'refund_repeat' => 'Same item refunded repeatedly',
            'refund_unmatched' => 'Refund without an earlier sale',
            'cancelled_rate' => 'Cancelled receipts above the chain',
            'cancelled_empty' => 'Cancelled receipts without lines',
            'cancelled_paid' => 'Cancelled receipts with a payment',
            'discount_deep' => 'Deep discounts',
            'discount_late' => 'Deep discounts at the end of the day',
            'off_hours' => 'Sales outside normal hours',
            'number_gap' => 'Missing receipt numbers',
            'duplicate_receipt' => 'Duplicate looking receipts',
            'silent_shop' => 'Silent shop',
            'silent_till' => 'Till never used',
            'data_total' => 'Sale receipt with total of zero or less',
            'data_qty' => 'Sale line with quantity of zero or less',
            'data_discount' => 'Negative discount',
            'data_future' => 'Receipt dated in the future',
            'data_shop' => 'Receipt shop differs from the till shop',
            'data_late' => 'Receipts arriving late',
            'data_flags' => 'Receipts accepted with a data warning',
            'unusual_basket' => 'Unusual single-item basket',
        ];
    }

    /** Signals that are not checked, with the reason (shown on the page). */
    public function notes(): array
    {
        return [
            ['signal' => __('Receipts arriving late'), 'reason' => __('Checked from the ingestion time (first insert only). Receipts stored before it was recorded have none and 1C history deliveries are left out, so they are not checked.')],
            ['signal' => __('Cancelled shortly after payment'), 'reason' => __('Replaced by "cancelled receipt with a recorded payment": payment rows carry no till time.')],
            ['signal' => __('Payments differing from the receipt total'), 'reason' => __('Not part of this board yet.')],
        ];
    }

    // ------------------------------------------------------------------------------------------------ refunds / cancelled

    /** Refund share (amount and count) of cashiers and tills against the rest of the chain. */
    public function refunds(Carbon $from, Carbon $to, array $shopIds): array
    {
        return $this->rateWarnings($from, $to, $shopIds, ['refund_amount', 'refund_count']);
    }

    /** Cancelled share, cancelled without lines, cancelled with a payment. */
    public function cancelled(Carbon $from, Carbon $to, array $shopIds): array
    {
        return $this->rateWarnings($from, $to, $shopIds, ['cancelled', 'cancelled_empty', 'cancelled_paid']);
    }

    /** Deep-discount sale receipts per cashier / till. */
    public function discounts(Carbon $from, Carbon $to, array $shopIds): array
    {
        return $this->rateWarnings($from, $to, $shopIds, ['discount_deep']);
    }

    /**
     * Compares each cashier (per shop) and each till with the rest of the chain for the given rate metrics. One row per
     * subject and warning type (when several metrics of one type are flagged, the one furthest above the baseline leads and
     * the others go into the evidence).
     */
    private function rateWarnings(Carbon $from, Carbon $to, array $shopIds, array $metrics): array
    {
        $fine = $this->fine($from, $to, $shopIds);
        $chain = $this->chain($fine);
        $cashiers = $this->grouped($fine, 'cashier');
        $tills = $this->grouped($fine, 'pos');
        $names = $this->names($shopIds);

        $rows = [];
        $flaggedCashierEvents = []; // type|pos => [cashier => events] (a till led by one flagged cashier is not repeated)

        foreach (['cashier' => $cashiers, 'till' => $tills] as $subject => $groups) {
            foreach ($groups as $g) {
                $byType = [];
                foreach ($metrics as $m) {
                    $j = $this->judge($m, $g, $chain);
                    if ($j !== null) {
                        $byType[self::RATES[$m]['type']][$m] = $j;
                    }
                }

                foreach ($byType as $type => $judged) {
                    uasort($judged, fn ($a, $b) => $b['score'] <=> $a['score']);
                    $metric = array_key_first($judged);
                    $j = $judged[$metric];

                    if ($subject === 'till' && $this->ledByOneCashier($fine, $g, self::RATES[$metric]['events'], $flaggedCashierEvents[$type] ?? [])) {
                        continue;
                    }

                    $def = self::RATES[$metric];
                    if ($subject === 'cashier') {
                        foreach ($fine as $f) {
                            if ($f['shop_id'] === $g['shop_id'] && $f['cashier'] === $g['cashier']) {
                                $flaggedCashierEvents[$type][$f['pos_id']][$g['shop_id'].'|'.$g['cashier']] = ($flaggedCashierEvents[$type][$f['pos_id']][$g['shop_id'].'|'.$g['cashier']] ?? 0) + $f[$def['events']];
                            }
                        }
                    }

                    $extra = [];
                    foreach ($judged as $other => $oj) {
                        if ($other !== $metric) {
                            $extra[] = __(':metric :rate % (rest of the chain :base %)', ['metric' => __($this->metricLabel($other)), 'rate' => $this->n($oj['rate']), 'base' => $this->n($oj['base'])]);
                        }
                    }

                    $ids = $this->eventIds($def['kind'], $from, $to, $shopIds, $g['shop_id'], $subject === 'cashier' ? $g['cashier'] : null, $subject === 'till' ? $g['pos_id'] : null);

                    $rows[] = $this->row($type, $j['severity'], [
                        'subject' => $subject,
                        'shop_id' => $g['shop_id'],
                        'shop' => $names['shops'][$g['shop_id']] ?? '#'.$g['shop_id'],
                        'cashier' => $subject === 'cashier' ? $g['cashier'] : null,
                        'pos_id' => $subject === 'till' ? $g['pos_id'] : null,
                        'pos' => $subject === 'till' ? ($names['pos'][$g['pos_id']] ?? '#'.$g['pos_id']) : null,
                        'metric' => $this->metricLabel($metric),
                        'value' => round($j['rate'], 1),
                        'unit' => '%',
                        'baseline' => round($j['base'], 1),
                        'baseline_note' => __('rest of the chain'),
                        'sample' => (int) $g[$def['sample']],
                        'finding' => __(':who: :metric is :rate % against :base % in the rest of the chain.', [
                            'who' => $this->who($subject, $g, $names),
                            'metric' => __($this->metricLabel($metric)),
                            'rate' => $this->n($j['rate']),
                            'base' => $this->n($j['base']),
                        ]),
                        'evidence' => trim(__(':events of :sample receipts in the period.', ['events' => (int) $g[$def['events']], 'sample' => (int) $g[$def['sample']]]).' '.implode('; ', $extra)),
                        'receipt_ids' => $ids,
                        'evidence_url' => $this->subjectUrl($subject, $g),
                    ]);
                }
            }
        }

        return $rows;
    }

    /** True when one flagged cashier produced >= 80 % of the till's events (the cashier row already says it). */
    private function ledByOneCashier(array $fine, array $till, string $eventField, array $flaggedByPos): bool
    {
        $tillEvents = (int) $till[$eventField];
        if ($tillEvents === 0 || ! isset($flaggedByPos[$till['pos_id']])) {
            return false;
        }

        return max($flaggedByPos[$till['pos_id']]) / $tillEvents >= 0.8;
    }

    /**
     * Score a group's rate. Returns null when it is not flagged.
     *
     * @return array{rate: float, base: float, severity: string, score: float}|null
     */
    private function judge(string $metric, array $g, array $chain): ?array
    {
        $def = self::RATES[$metric];
        $sample = (int) $g[$def['sample']];
        $events = (int) $g[$def['events']];

        if ($sample < config('security.min_receipts') || $events < config('security.min_events') || $g[$def['den']] <= 0) {
            return null;
        }

        $rest = [];
        foreach ([...self::FIELDS, 'txn_count'] as $f) {
            $rest[$f] = $chain[$f] - $g[$f];
        }
        if ($rest['all_count'] < config('security.min_baseline_receipts')) {
            return null; // nothing to compare with
        }

        $rate = $g[$def['num']] / $g[$def['den']] * 100;
        $base = $rest[$def['den']] > 0 ? $rest[$def['num']] / $rest[$def['den']] * 100 : 0.0;
        [$floor, $highFloor] = config('security.floors.'.$metric);
        $ratio = $base > 0 ? $rate / $base : INF;

        if ($rate < $floor || $ratio < config('security.ratio_flag')) {
            return null;
        }

        $big = $sample >= config('security.high_sample');
        $severity = $big && $ratio >= config('security.ratio_high') && $rate >= $highFloor ? 'high' : ($big ? 'medium' : 'low');

        return ['rate' => $rate, 'base' => $base, 'severity' => $severity, 'score' => is_finite($ratio) ? $ratio : 1000 + $rate];
    }

    private function metricLabel(string $metric): string
    {
        return [
            'refund_amount' => 'Refund amount share of sales',
            'refund_count' => 'Refund share of receipts',
            'cancelled' => 'Cancelled share of all receipts',
            'cancelled_empty' => 'Share of cancelled receipts without lines',
            'cancelled_paid' => 'Share of cancelled receipts with a payment',
            'discount_deep' => 'Share of deep-discount sale receipts',
        ][$metric];
    }

    /** The same item refunded again and again by one cashier. */
    public function repeatedRefunds(Carbon $from, Carbon $to, array $shopIds): array
    {
        $min = (int) config('security.repeat_refund_min');

        $rows = $this->base($from, $to, $shopIds)
            ->where('receipts.active', true)->where('receipts.sell', false)
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false)
            ->groupBy('receipts.shop_id', 'receipts.cashier', 'receipt_items.item_id')
            ->selectRaw('receipts.shop_id, receipts.cashier, receipt_items.item_id, COUNT(DISTINCT receipts.id) as cnt, COALESCE(SUM(ABS(receipt_items.total)), 0) as amount')
            ->havingRaw('COUNT(DISTINCT receipts.id) >= ?', [$min])
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = $this->names($shopIds);
        $items = DB::table('items')->whereIn('id', $rows->pluck('item_id'))->pluck('name', 'id');
        $out = [];

        foreach ($rows as $r) {
            $cashier = (string) ($r->cashier ?? '');
            $idQuery = $this->base($from, $to, $shopIds)
                ->where('receipts.active', true)->where('receipts.sell', false)
                ->where('receipts.shop_id', $r->shop_id);
            $ids = $this->cashierWhere($idQuery, $cashier)
                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('receipt_items as ri')->whereColumn('ri.receipt_id', 'receipts.id')->where('ri.item_id', $r->item_id)->where('ri.storno', false))
                ->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($id) => (int) $id)->all();

            $g = ['shop_id' => (int) $r->shop_id, 'cashier' => $cashier];
            $out[] = $this->row('refund_repeat', (int) $r->cnt >= config('security.repeat_refund_high') ? 'high' : 'medium', [
                'subject' => 'cashier',
                'shop_id' => (int) $r->shop_id,
                'shop' => $names['shops'][$r->shop_id] ?? '#'.$r->shop_id,
                'cashier' => $cashier,
                'metric' => 'Refund receipts of one item',
                'value' => (int) $r->cnt,
                'unit' => 'count',
                'baseline' => null,
                'baseline_note' => __('normally rare'),
                'sample' => (int) $r->cnt,
                'finding' => __(':who refunded ":item" in :count different receipts.', ['who' => $this->who('cashier', $g, $names), 'item' => $items[$r->item_id] ?? '#'.$r->item_id, 'count' => (int) $r->cnt]),
                'evidence' => __(':count refund receipts, refunded amount :amount.', ['count' => (int) $r->cnt, 'amount' => number_format((float) $r->amount, 0, '.', ' ')]),
                'receipt_ids' => $ids,
                'evidence_url' => $this->subjectUrl('cashier', $g),
            ]);
        }

        return $out;
    }

    /**
     * Refund lines of an item that was not sold in the same shop in the look-back window before the refund (window starts
     * N days before the period). Skipped for shops whose receipt history is shorter than the window (no false alarms
     * right after a migration).
     */
    public function unmatchedRefunds(Carbon $from, Carbon $to, array $shopIds): array
    {
        $lower = $from->copy()->subDays((int) config('security.refund_lookback_days'))->startOfDay();

        $firstSeen = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->groupBy('receipts.shop_id')->selectRaw('receipts.shop_id, MIN(receipts.created_at) as first_at')
            ->pluck('first_at', 'shop_id');
        $eligible = $firstSeen->filter(fn ($t) => $t <= $lower->format('Y-m-d H:i:s'))->keys()->map(fn ($id) => (int) $id)->all();
        if ($eligible === []) {
            return [];
        }

        $rows = $this->base($from, $to, $eligible)
            ->where('receipts.active', true)->where('receipts.sell', false)
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false)
            ->whereNotExists(function ($s) use ($lower) {
                $s->select(DB::raw(1))->from('receipts as sr')
                    ->join('receipt_items as si', 'si.receipt_id', '=', 'sr.id')
                    ->whereColumn('sr.shop_id', 'receipts.shop_id')
                    ->whereColumn('si.item_id', 'receipt_items.item_id')
                    ->where('sr.active', true)->where('sr.sell', true)->where('si.storno', false)
                    ->whereColumn('sr.created_at', '<=', 'receipts.created_at')
                    ->where('sr.created_at', '>=', $lower);
            })
            ->groupBy('receipts.shop_id', 'receipts.cashier')
            ->selectRaw('receipts.shop_id, receipts.cashier, COUNT(DISTINCT receipts.id) as cnt, COUNT(receipt_items.id) as line_cnt, MAX(receipts.id) as last_id')
            ->havingRaw('COUNT(DISTINCT receipts.id) >= ?', [(int) config('security.unmatched_refund_min')])
            ->orderByDesc('cnt')->limit(50)->get();

        $names = $this->names($shopIds);
        $out = [];
        foreach ($rows as $r) {
            $cashier = (string) ($r->cashier ?? '');
            $g = ['shop_id' => (int) $r->shop_id, 'cashier' => $cashier];
            $out[] = $this->row('refund_unmatched', (int) $r->cnt >= config('security.unmatched_refund_high') ? 'high' : 'medium', [
                'subject' => 'cashier',
                'shop_id' => (int) $r->shop_id,
                'shop' => $names['shops'][$r->shop_id] ?? '#'.$r->shop_id,
                'cashier' => $cashier,
                'metric' => 'Refund receipts without an earlier sale',
                'value' => (int) $r->cnt,
                'unit' => 'count',
                'baseline' => null,
                'baseline_note' => __('expected 0'),
                'sample' => (int) $r->cnt,
                'finding' => __(':who has :count refund receipts for items not sold in this shop in the last :days days before the refund.', ['who' => $this->who('cashier', $g, $names), 'count' => (int) $r->cnt, 'days' => (int) config('security.refund_lookback_days')]),
                'evidence' => __(':lines refunded lines in :count receipts; the look-back starts :days days before the period.', ['lines' => (int) $r->line_cnt, 'count' => (int) $r->cnt, 'days' => (int) config('security.refund_lookback_days')]),
                'receipt_ids' => [(int) $r->last_id],
                'evidence_url' => $this->subjectUrl('cashier', $g),
            ]);
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ discounts

    /** Deep discounts in the last minutes before the shop's last sale of the day. */
    public function lateDiscounts(Carbon $from, Carbon $to, array $shopIds): array
    {
        $pct = (float) config('security.deep_discount_pct');
        $minutes = (int) config('security.end_of_day_minutes');

        $deep = $this->sales($from, $to, $shopIds)
            ->whereRaw('receipts.total + receipts.discount > 0 AND receipts.discount * 100 >= ? * (receipts.total + receipts.discount)', [$pct])
            ->orderBy('receipts.created_at')->limit(5000)
            ->get(['receipts.id', 'receipts.shop_id', 'receipts.cashier', 'receipts.created_at']);
        if ($deep->isEmpty()) {
            return [];
        }

        $days = $this->sales($from, $to, $shopIds)
            ->groupBy('receipts.shop_id', DB::raw($this->dateExpr()))
            ->selectRaw('receipts.shop_id, '.$this->dateExpr().' as dd, MAX(receipts.created_at) as last_at, COUNT(*) as cnt')
            ->get()->mapWithKeys(fn ($r) => [$r->shop_id.'|'.$r->dd => ['last' => strtotime((string) $r->last_at), 'cnt' => (int) $r->cnt]]);

        $per = [];
        $lateAll = 0;
        foreach ($deep as $r) {
            $key = $r->shop_id.'|'.(string) ($r->cashier ?? '');
            $day = $days[$r->shop_id.'|'.substr((string) $r->created_at, 0, 10)] ?? null;
            $late = $day && $day['cnt'] >= config('security.end_of_day_min_day_receipts') && strtotime((string) $r->created_at) >= $day['last'] - $minutes * 60;
            $per[$key]['shop_id'] = (int) $r->shop_id;
            $per[$key]['cashier'] = (string) ($r->cashier ?? '');
            $per[$key]['deep'] = ($per[$key]['deep'] ?? 0) + 1;
            $per[$key]['late'] = ($per[$key]['late'] ?? 0) + ($late ? 1 : 0);
            if ($late) {
                $per[$key]['ids'][] = (int) $r->id;
                $lateAll++;
            }
        }
        $chainShare = $deep->count() > 0 ? $lateAll / $deep->count() * 100 : 0.0;

        $names = $this->names($shopIds);
        $out = [];
        foreach ($per as $p) {
            if ($p['late'] < config('security.end_of_day_min')) {
                continue;
            }
            $share = $p['late'] / $p['deep'] * 100;
            $severity = $p['late'] >= config('security.end_of_day_high') && $share >= 50 ? 'high' : ($share >= 50 ? 'medium' : 'low');
            $g = ['shop_id' => $p['shop_id'], 'cashier' => $p['cashier']];
            $out[] = $this->row('discount_late', $severity, [
                'subject' => 'cashier',
                'shop_id' => $p['shop_id'],
                'shop' => $names['shops'][$p['shop_id']] ?? '#'.$p['shop_id'],
                'cashier' => $p['cashier'],
                'metric' => 'Deep discounts in the last minutes of the day',
                'value' => $p['late'],
                'unit' => 'count',
                'baseline' => round($chainShare, 1),
                'baseline_note' => __('% of all deep discounts in the chain are that late'),
                'sample' => $p['deep'],
                'finding' => __(':who gave :late of :deep deep discounts (:pct % or more) in the last :minutes minutes before the shop\'s last sale of the day.', ['who' => $this->who('cashier', $g, $names), 'late' => $p['late'], 'deep' => $p['deep'], 'pct' => $this->n($pct, 0), 'minutes' => $minutes]),
                'evidence' => __(':share % of this cashier\'s deep discounts are that late (chain :chain %).', ['share' => $this->n($share), 'chain' => $this->n($chainShare)]),
                'receipt_ids' => array_slice($p['ids'] ?? [], -5),
                'evidence_url' => $this->subjectUrl('cashier', $g),
            ]);
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ off hours / silent

    /** Sale receipts in hours that carry < 1 % of the shop's own volume of the last 28 days. */
    public function offHours(Carbon $from, Carbon $to, array $shopIds): array
    {
        $profile = $this->hourProfile($to, $shopIds);
        $off = [];
        foreach ($profile as $shop => $p) {
            if ($p['total'] >= config('security.off_hours_min_baseline_receipts')) {
                $off[$shop] = array_values(array_filter(range(0, 23), fn ($h) => ($p['hours'][$h] ?? 0) / $p['total'] * 100 < config('security.off_hours_share_pct')));
            }
        }
        if ($off === []) {
            return [];
        }

        $rows = $this->sales($from, $to, array_keys($off))
            ->groupBy('receipts.shop_id', 'receipts.cashier', DB::raw($this->hourExpr()))
            ->selectRaw('receipts.shop_id, receipts.cashier, '.$this->hourExpr().' as hh, COUNT(*) as cnt')
            ->get();

        $per = [];
        foreach ($rows as $r) {
            if (! in_array((int) $r->hh, $off[$r->shop_id] ?? [], true)) {
                continue;
            }
            $key = $r->shop_id.'|'.(string) ($r->cashier ?? '');
            $per[$key]['shop_id'] = (int) $r->shop_id;
            $per[$key]['cashier'] = (string) ($r->cashier ?? '');
            $per[$key]['count'] = ($per[$key]['count'] ?? 0) + (int) $r->cnt;
            $per[$key]['hours'][(int) $r->hh] = (int) $r->cnt;
        }

        $names = $this->names($shopIds);
        $out = [];
        foreach ($per as $p) {
            ksort($p['hours']);
            $hours = array_keys($p['hours']);
            $severity = $this->steps($p['count'], (int) config('security.off_hours_medium'), (int) config('security.off_hours_high'));
            $g = ['shop_id' => $p['shop_id'], 'cashier' => $p['cashier']];
            $out[] = $this->row('off_hours', $severity, [
                'subject' => 'cashier',
                'shop_id' => $p['shop_id'],
                'shop' => $names['shops'][$p['shop_id']] ?? '#'.$p['shop_id'],
                'cashier' => $p['cashier'],
                'metric' => 'Sale receipts outside normal hours',
                'value' => $p['count'],
                'unit' => 'count',
                'baseline' => null,
                'baseline_note' => __('less than :pct % of the shop volume at these hours', ['pct' => $this->n(config('security.off_hours_share_pct'), 0)]),
                'sample' => (int) $profile[$p['shop_id']]['total'],
                'finding' => __(':who rang :count sale receipts at hours the shop is normally closed (:hours).', ['who' => $this->who('cashier', $g, $names), 'count' => $p['count'], 'hours' => implode(', ', array_map(fn ($h) => sprintf('%02d:00', $h), $hours))]),
                'evidence' => __('Normal hours come from :n sale receipts of the shop in the last :days days.', ['n' => (int) $profile[$p['shop_id']]['total'], 'days' => (int) config('security.baseline_days')]),
                'receipt_ids' => $this->hourIds($from, $to, $p['shop_id'], $p['cashier'], $hours),
                'evidence_url' => $this->subjectUrl('cashier', $g),
            ]);
        }

        return $out;
    }

    /**
     * Usual opening hours (>= 1 % of the shop's 28-day volume) without a single sale receipt for N hours in a row, when the
     * same weekday median says receipts were normally there. Today only counts completed hours.
     */
    public function silentShops(Carbon $from, Carbon $to, array $shopIds, ?Carbon $now = null): array
    {
        $now ??= now();
        $rows = $this->hourly($to, $shopIds);
        $start = $to->copy()->subDays((int) config('security.baseline_days') - 1)->startOfDay();
        $today = $now->toDateString();
        $lastDate = min($to->toDateString(), $today);

        $by = [];
        foreach ($rows as $r) {
            $by[$r->shop_id][$r->dd][(int) $r->hh] = (int) $r->cnt;
        }

        $names = $this->names($shopIds);
        $out = [];

        foreach ($this->hourProfile($to, $shopIds) as $shop => $p) {
            if ($p['total'] < config('security.off_hours_min_baseline_receipts')) {
                continue;
            }
            $usual = array_values(array_filter(range(0, 23), fn ($h) => ($p['hours'][$h] ?? 0) / $p['total'] * 100 >= config('security.off_hours_share_pct')));

            // Completed dates of the baseline window, by weekday.
            $weekday = [];
            for ($d = $start->copy(); $d->toDateString() <= $to->toDateString() && $d->toDateString() < $today; $d->addDay()) {
                $weekday[$d->dayOfWeek][] = $d->toDateString();
            }

            $stretches = [];
            for ($d = max($from, $start)->copy()->startOfDay(); $d->toDateString() <= $lastDate; $d->addDay()) {
                $date = $d->toDateString();
                $others = array_values(array_diff($weekday[$d->dayOfWeek] ?? [], [$date]));
                if (count($others) < config('security.silent_min_weekday_days')) {
                    continue;
                }

                $run = [];
                $expected = 0.0;
                $flush = function () use (&$run, &$expected, &$stretches, $date) {
                    if (count($run) >= config('security.silent_hours') && $expected >= config('security.silent_min_expected')) {
                        $stretches[] = ['date' => $date, 'from' => $run[0], 'to' => end($run), 'len' => count($run), 'expected' => $expected];
                    }
                    $run = [];
                    $expected = 0.0;
                };

                foreach ($usual as $h) {
                    $done = $date < $today || $h < (int) $now->format('G');
                    $silent = $done && ($by[$shop][$date][$h] ?? 0) === 0;
                    if ($silent && ($run === [] || end($run) === $h - 1)) {
                        $run[] = $h;
                        $expected += $this->median(array_map(fn ($o) => $by[$shop][$o][$h] ?? 0, $others));
                    } else {
                        $flush();
                        if ($silent) {
                            $run = [$h];
                            $expected = $this->median(array_map(fn ($o) => $by[$shop][$o][$h] ?? 0, $others));
                        }
                    }
                }
                $flush();
            }

            if ($stretches === []) {
                continue;
            }

            usort($stretches, fn ($a, $b) => [$b['len'], $b['expected']] <=> [$a['len'], $a['expected']]);
            $w = $stretches[0];
            $out[] = $this->row('silent_shop', $w['len'] >= config('security.silent_high_hours') ? 'high' : 'medium', [
                'subject' => 'shop',
                'shop_id' => (int) $shop,
                'shop' => $names['shops'][$shop] ?? '#'.$shop,
                'metric' => 'Hours in a row without a sale receipt',
                'value' => $w['len'],
                'unit' => 'h',
                'baseline' => round($w['expected'], 0),
                'baseline_note' => __('receipts expected by the same weekday median'),
                'sample' => (int) $p['total'],
                'finding' => __(':shop had no sale receipt on :date from :from to :to, hours it normally trades.', ['shop' => $names['shops'][$shop] ?? '#'.$shop, 'date' => $w['date'], 'from' => sprintf('%02d:00', $w['from']), 'to' => sprintf('%02d:59', $w['to'])]),
                'evidence' => __(':count silent stretches in the period; the longest is :len hours where about :exp receipts are normal.', ['count' => count($stretches), 'len' => $w['len'], 'exp' => (int) round($w['expected'])]),
                'receipt_ids' => [],
                'evidence_url' => route('receipts.index', ['date' => $w['date']]),
            ]);
        }

        return $out;
    }

    /** Tills registered in a shop but with no receipt ever (shop trades normally in the period). */
    public function idleTills(Carbon $from, Carbon $to, array $shopIds): array
    {
        $sales = $this->sales($from, $to, $shopIds)->groupBy('receipts.shop_id')->selectRaw('receipts.shop_id, COUNT(*) as cnt')->pluck('cnt', 'shop_id');
        $active = $sales->filter(fn ($c) => $c >= config('security.min_receipts'))->keys()->map(fn ($id) => (int) $id)->all();
        if ($active === []) {
            return [];
        }

        $names = $this->names($shopIds);

        return DB::table('pos')->whereIn('shop_id', $active)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('receipts')->whereColumn('receipts.pos_id', 'pos.id'))
            ->orderBy('pos.id')->limit(50)->get(['pos.id', 'pos.name', 'pos.shop_id'])
            ->map(fn ($p) => $this->row('silent_till', 'low', [
                'subject' => 'till',
                'shop_id' => (int) $p->shop_id,
                'shop' => $names['shops'][$p->shop_id] ?? '#'.$p->shop_id,
                'pos_id' => (int) $p->id,
                'pos' => $p->name,
                'metric' => 'Receipts of this till',
                'value' => 0,
                'unit' => 'count',
                'baseline' => null,
                'baseline_note' => __('the shop rang :n sale receipts in the period', ['n' => (int) $sales[$p->shop_id]]),
                'sample' => (int) $sales[$p->shop_id],
                'finding' => __('Till :pos of :shop is registered but has never sent a receipt.', ['pos' => $p->name, 'shop' => $names['shops'][$p->shop_id] ?? '#'.$p->shop_id]),
                'evidence' => __('No receipt of this till exists at all; the shop rang :n sale receipts in the period.', ['n' => (int) $sales[$p->shop_id]]),
                'receipt_ids' => [],
                'evidence_url' => route('pos.index'),
            ]))->all();
    }

    // ------------------------------------------------------------------------------------------------ numbering

    /** Missing numbers inside a (till, shift). Shifts without a session (0) are skipped: numbers restart there. */
    public function numberGaps(Carbon $from, Carbon $to, array $shopIds): array
    {
        $cast = 'CAST(receipts.number AS UNSIGNED)';

        $rows = $this->base($from, $to, $shopIds)
            ->where('receipts.session', '>', 0)
            ->groupBy('receipts.pos_id', 'receipts.session')
            ->selectRaw("receipts.pos_id, MAX(receipts.shop_id) as shop_id, receipts.session, COUNT(*) as cnt, MIN({$cast}) as mn, MAX({$cast}) as mx")
            ->havingRaw("COUNT(*) >= ? AND MIN({$cast}) >= 1 AND (MAX({$cast}) - MIN({$cast}) + 1 - COUNT(*)) > 0", [(int) config('security.gap_min_receipts')])
            ->get()
            ->map(function ($r) {
                $r->missing = (int) $r->mx - (int) $r->mn + 1 - (int) $r->cnt;

                return $r;
            })
            ->filter(fn ($r) => $r->mx - $r->mn < 100000)
            ->sortByDesc('missing')->take(50)->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = $this->names($shopIds);
        $out = [];
        foreach ($rows as $i => $r) {
            $share = $r->missing / ($r->mx - $r->mn + 1) * 100;
            $severity = $r->missing >= config('security.gap_high_missing') && $share >= config('security.gap_high_share_pct') ? 'high'
                : ($r->missing >= config('security.gap_medium_missing') ? 'medium' : 'low');

            $list = '';
            if ($i < 30) {
                $have = $this->base($from, $to, $shopIds)->where('receipts.pos_id', $r->pos_id)->where('receipts.session', $r->session)->pluck('receipts.number')->map(fn ($n) => (int) $n)->flip();
                $missing = [];
                for ($n = (int) $r->mn; $n <= (int) $r->mx && count($missing) < 10; $n++) {
                    if (! isset($have[$n])) {
                        $missing[] = $n;
                    }
                }
                $list = implode(', ', $missing);
            }

            $out[] = $this->row('number_gap', $severity, [
                'subject' => 'till',
                'shop_id' => (int) $r->shop_id,
                'shop' => $names['shops'][$r->shop_id] ?? '#'.$r->shop_id,
                'pos_id' => (int) $r->pos_id,
                'pos' => $names['pos'][$r->pos_id] ?? '#'.$r->pos_id,
                'metric' => 'Missing receipt numbers in the shift',
                'value' => $r->missing,
                'unit' => 'count',
                'baseline' => 0,
                'baseline_note' => __('expected 0'),
                'sample' => (int) $r->cnt,
                'finding' => __('Till :pos (shift :session) is missing :missing of the numbers :min to :max.', ['pos' => $names['pos'][$r->pos_id] ?? '#'.$r->pos_id, 'session' => $r->session, 'missing' => $r->missing, 'min' => $r->mn, 'max' => $r->mx]),
                'evidence' => __(':have receipts arrived, :pct % of the range is missing.', ['have' => (int) $r->cnt, 'pct' => $this->n($share)]).($list !== '' ? ' '.__('Missing: :list', ['list' => $list]) : ''),
                'receipt_ids' => [],
                'evidence_url' => route('receipts.index', ['date' => $to->toDateString()]),
            ]);
        }

        return $out;
    }

    /** Same till + number + total in different shifts within a minute (a receipt sent twice under another shift). */
    public function duplicates(Carbon $from, Carbon $to, array $shopIds): array
    {
        $rows = $this->base($from, $to, $shopIds)
            ->where('receipts.total', '<>', 0)
            ->groupBy('receipts.pos_id', 'receipts.number', 'receipts.total', 'receipts.sell', 'receipts.active')
            ->havingRaw('COUNT(*) > 1 AND COUNT(DISTINCT receipts.session) > 1')
            ->selectRaw('receipts.pos_id, MAX(receipts.shop_id) as shop_id, receipts.number, receipts.total, COUNT(*) as cnt, MIN(receipts.created_at) as t1, MAX(receipts.created_at) as t2, GROUP_CONCAT(receipts.id) as ids')
            ->limit(500)->get()
            ->filter(fn ($r) => strtotime((string) $r->t2) - strtotime((string) $r->t1) <= config('security.duplicate_seconds'))
            ->take(50);

        $names = $this->names($shopIds);

        return $rows->map(fn ($r) => $this->row('duplicate_receipt', (int) $r->cnt >= 3 ? 'high' : 'medium', [
            'subject' => 'till',
            'shop_id' => (int) $r->shop_id,
            'shop' => $names['shops'][$r->shop_id] ?? '#'.$r->shop_id,
            'pos_id' => (int) $r->pos_id,
            'pos' => $names['pos'][$r->pos_id] ?? '#'.$r->pos_id,
            'metric' => 'Copies of one receipt number',
            'value' => (int) $r->cnt,
            'unit' => 'count',
            'baseline' => 1,
            'baseline_note' => __('expected 1'),
            'sample' => (int) $r->cnt,
            'finding' => __('Receipt :number of till :pos exists :count times in different shifts within :sec seconds, all with the total :total.', ['number' => $r->number, 'pos' => $names['pos'][$r->pos_id] ?? '#'.$r->pos_id, 'count' => (int) $r->cnt, 'sec' => (int) config('security.duplicate_seconds'), 'total' => number_format((float) $r->total, 0, '.', ' ')]),
            'evidence' => __('First :t1, last :t2.', ['t1' => substr((string) $r->t1, 11, 8), 't2' => substr((string) $r->t2, 11, 8)]),
            'receipt_ids' => array_slice(array_map('intval', explode(',', (string) $r->ids)), 0, 5),
            'evidence_url' => route('receipts.show', ['receipt' => (int) explode(',', (string) $r->ids)[0]]),
        ]))->values()->all();
    }

    // ------------------------------------------------------------------------------------------------ data integrity

    /** Impossible or suspicious values in the feed, per shop. */
    public function dataIntegrity(Carbon $from, Carbon $to, array $shopIds, ?Carbon $now = null): array
    {
        $now ??= now();
        $names = $this->names($shopIds);
        $out = [];

        $stats = $this->base($from, $to, $shopIds)
            ->join('pos', 'pos.id', '=', 'receipts.pos_id')
            ->groupBy('receipts.shop_id')
            ->selectRaw('receipts.shop_id,
                SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 THEN 1 ELSE 0 END) as sale_count,
                SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND receipts.total <= 0 THEN 1 ELSE 0 END) as bad_total,
                SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND receipts.discount < 0 THEN 1 ELSE 0 END) as neg_discount,
                SUM(CASE WHEN pos.shop_id IS NOT NULL AND pos.shop_id <> receipts.shop_id THEN 1 ELSE 0 END) as wrong_shop')
            ->get();

        $qty = $this->sales($from, $to, $shopIds)
            ->join('receipt_items', 'receipt_items.receipt_id', '=', 'receipts.id')
            ->where('receipt_items.storno', false)->where('receipt_items.qty', '<=', 0)
            ->groupBy('receipts.shop_id')
            ->selectRaw('receipts.shop_id, COUNT(DISTINCT receipts.id) as cnt, COUNT(receipt_items.id) as line_cnt')
            ->get()->keyBy('shop_id');

        $future = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->where('receipts.created_at', '>', $now->copy()->addMinutes((int) config('security.future_minutes')))
            ->groupBy('receipts.shop_id')
            ->selectRaw('receipts.shop_id, COUNT(*) as cnt, MAX(receipts.created_at) as latest')
            ->get()->keyBy('shop_id');

        $make = function (string $type, int $shopId, int $count, int $sales, string $finding, string $evidence, array $ids, ?string $severity = null) use ($names) {
            $share = $sales > 0 ? $count / $sales * 100 : 0.0;
            $severity ??= $count >= config('security.data_min_events_high') && $sales >= config('security.min_receipts') && $share >= config('security.data_high_share_pct') ? 'high' : 'medium';

            return $this->row($type, $severity, [
                'subject' => 'shop',
                'shop_id' => $shopId,
                'shop' => $names['shops'][$shopId] ?? '#'.$shopId,
                'metric' => self::typeLabels()[$type],
                'value' => $count,
                'unit' => 'count',
                'baseline' => 0,
                'baseline_note' => __('expected 0'),
                'sample' => $sales,
                'finding' => $finding,
                'evidence' => $evidence,
                'receipt_ids' => $ids,
                'evidence_url' => $ids !== [] ? route('receipts.show', ['receipt' => $ids[0]]) : route('receipts.index'),
            ]);
        };

        foreach ($stats as $s) {
            $shop = (int) $s->shop_id;
            $label = $names['shops'][$shop] ?? '#'.$shop;
            $sales = (int) $s->sale_count;

            if ((int) $s->bad_total > 0) {
                $ids = $this->sales($from, $to, [$shop])->where('receipts.total', '<=', 0)->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($i) => (int) $i)->all();
                $out[] = $make('data_total', $shop, (int) $s->bad_total, $sales, __(':shop has :count sale receipts with a total of zero or less.', ['shop' => $label, 'count' => (int) $s->bad_total]), __(':count of :sales sale receipts.', ['count' => (int) $s->bad_total, 'sales' => $sales]), $ids);
            }
            if ((int) $s->neg_discount > 0) {
                $ids = $this->sales($from, $to, [$shop])->where('receipts.discount', '<', 0)->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($i) => (int) $i)->all();
                $out[] = $make('data_discount', $shop, (int) $s->neg_discount, $sales, __(':shop has :count sale receipts where the sum before discount is below the total (negative discount).', ['shop' => $label, 'count' => (int) $s->neg_discount]), __(':count of :sales sale receipts.', ['count' => (int) $s->neg_discount, 'sales' => $sales]), $ids);
            }
            if ((int) $s->wrong_shop > 0) {
                $ids = $this->base($from, $to, [$shop])->join('pos', 'pos.id', '=', 'receipts.pos_id')->whereNotNull('pos.shop_id')->whereColumn('pos.shop_id', '<>', 'receipts.shop_id')->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($i) => (int) $i)->all();
                $out[] = $make('data_shop', $shop, (int) $s->wrong_shop, $sales, __(':shop received :count receipts from tills registered in another shop.', ['shop' => $label, 'count' => (int) $s->wrong_shop]), __('The shop in the receipt and the shop of its till differ.'), $ids);
            }
        }

        foreach ($qty as $shop => $q) {
            $label = $names['shops'][$shop] ?? '#'.$shop;
            $sales = (int) ($stats->firstWhere('shop_id', $shop)->sale_count ?? 0);
            $ids = $this->sales($from, $to, [(int) $shop])
                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('receipt_items as ri')->whereColumn('ri.receipt_id', 'receipts.id')->where('ri.storno', false)->where('ri.qty', '<=', 0))
                ->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($i) => (int) $i)->all();
            $out[] = $make('data_qty', (int) $shop, (int) $q->cnt, $sales, __(':shop has :count sale receipts with a line of zero or negative quantity.', ['shop' => $label, 'count' => (int) $q->cnt]), __(':lines lines in :count receipts.', ['lines' => (int) $q->line_cnt, 'count' => (int) $q->cnt]), $ids);
        }

        foreach ($future as $shop => $f) {
            $label = $names['shops'][$shop] ?? '#'.$shop;
            $sales = (int) ($stats->firstWhere('shop_id', $shop)->sale_count ?? 0);
            $ids = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', [(int) $shop])->where('receipts.created_at', '>', $now->copy()->addMinutes((int) config('security.future_minutes')))->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($i) => (int) $i)->all();
            $far = strtotime((string) $f->latest) > $now->copy()->addDay()->getTimestamp();
            $out[] = $make('data_future', (int) $shop, (int) $f->cnt, $sales, __(':shop has :count receipts dated more than :min minutes in the future (clock problem?).', ['shop' => $label, 'count' => (int) $f->cnt, 'min' => (int) config('security.future_minutes')]), __('Latest receipt time: :t.', ['t' => substr((string) $f->latest, 0, 16)]), $ids, $far ? 'high' : 'medium');
        }

        // Late-arriving receipts: till receipts that reached us more than late_hours after their own time. Judged by the
        // INGESTION time (receipts.ingested_at, first insert only): rows stored before it was recorded have none and are not
        // checked; 1C history deliveries are left out (they are late by design).
        $lateHours = (int) config('receipts.late_hours', 48);
        $late = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->where('receipts.source', 'pos')
            ->whereBetween('receipts.ingested_at', [$from, $to])
            ->groupBy('receipts.shop_id')
            ->selectRaw('receipts.shop_id, COUNT(*) as arrived, SUM(CASE WHEN '.$this->lateSql().' THEN 1 ELSE 0 END) as late_cnt, MIN(receipts.created_at) as oldest', [$lateHours])
            ->get();

        foreach ($late as $l) {
            if ((int) $l->late_cnt === 0) {
                continue;
            }
            $shop = (int) $l->shop_id;
            $label = $names['shops'][$shop] ?? '#'.$shop;
            $ids = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', [$shop])
                ->where('receipts.source', 'pos')->whereBetween('receipts.ingested_at', [$from, $to])
                ->whereRaw($this->lateSql(), [$lateHours])
                ->orderByDesc('receipts.ingested_at')->limit(5)->pluck('receipts.id')->map(fn ($i) => (int) $i)->all();
            $out[] = $make('data_late', $shop, (int) $l->late_cnt, (int) $l->arrived,
                __(':shop received :count till receipts more than :h hours after their own time (offline queue or clock problem?).', ['shop' => $label, 'count' => (int) $l->late_cnt, 'h' => $lateHours]),
                __(':count of :arrived receipts that arrived in the period; the oldest is dated :t.', ['count' => (int) $l->late_cnt, 'arrived' => (int) $l->arrived, 't' => substr((string) $l->oldest, 0, 16)]),
                $ids);
        }

        // Receipts flagged at ingestion (receipts.dq_flags). bad_total / sum_lt_total have their own rows above.
        $flagged = $this->base($from, $to, $shopIds)->whereNotNull('receipts.dq_flags')->where('receipts.dq_flags', '<>', '')
            ->groupBy('receipts.shop_id', 'receipts.dq_flags')
            ->selectRaw('receipts.shop_id, receipts.dq_flags, COUNT(*) as cnt')
            ->get();
        $byShop = [];
        foreach ($flagged as $f) {
            $codes = array_values(array_diff(explode(',', (string) $f->dq_flags), ReceiptChecks::COVERED_ELSEWHERE));
            if ($codes === []) {
                continue;
            }
            $byShop[(int) $f->shop_id]['count'] = ($byShop[(int) $f->shop_id]['count'] ?? 0) + (int) $f->cnt;
            foreach ($codes as $c) {
                $byShop[(int) $f->shop_id]['codes'][$c] = ($byShop[(int) $f->shop_id]['codes'][$c] ?? 0) + (int) $f->cnt;
            }
        }
        foreach ($byShop as $shop => $info) {
            $label = $names['shops'][$shop] ?? '#'.$shop;
            $sales = (int) ($stats->firstWhere('shop_id', $shop)->sale_count ?? 0);
            $ids = $this->base($from, $to, [$shop])->where(function ($w) {
                foreach (array_diff(ReceiptChecks::FLAGS, ReceiptChecks::COVERED_ELSEWHERE) as $code) {
                    $w->orWhere('receipts.dq_flags', 'like', '%'.$code.'%');
                }
            })->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($i) => (int) $i)->all();
            $parts = [];
            foreach ($info['codes'] as $code => $n) {
                $parts[] = __(ReceiptChecks::flagLabels()[$code] ?? $code).': '.$n;
            }
            $out[] = $make('data_flags', $shop, $info['count'], $sales,
                __(':shop has :count receipts that were accepted with a data warning.', ['shop' => $label, 'count' => $info['count']]),
                implode('; ', $parts).'.', $ids, 'medium');
        }

        return $out;
    }

    private function lateSql(): string
    {
        return ReceiptChecks::lateSql();
    }

    /** One line, a large quantity and a total far above the shop's big-receipt cut (wholesale or a typed-in quantity). */
    public function unusualBaskets(Carbon $from, Carbon $to, array $shopIds): array
    {
        $counts = $this->sales($from, $to, $shopIds)->groupBy('receipts.shop_id')->selectRaw('receipts.shop_id, COUNT(*) as cnt')->pluck('cnt', 'shop_id');
        $names = $this->names($shopIds);
        $out = [];

        foreach ($counts as $shop => $n) {
            if ($n < config('security.basket_min_receipts')) {
                continue;
            }

            $offset = max(0, (int) floor($n * config('security.basket_cut_top_share')) - 1);
            $cut = (float) $this->sales($from, $to, [(int) $shop])->orderByDesc('receipts.total')->offset($offset)->limit(1)->value('receipts.total');
            if ($cut <= 0) {
                continue;
            }

            $candidates = $this->sales($from, $to, [(int) $shop])
                ->where('receipts.total', '>=', $cut * config('security.basket_cut_multiplier'))
                ->orderByDesc('receipts.total')->limit(200)
                ->get(['receipts.id', 'receipts.cashier', 'receipts.total']);
            if ($candidates->isEmpty()) {
                continue;
            }

            $lines = DB::table('receipt_items')->whereIn('receipt_id', $candidates->pluck('id'))->where('storno', false)
                ->get(['receipt_id', 'item_id', 'qty'])->groupBy('receipt_id');
            $itemNames = DB::table('items')->whereIn('id', $lines->flatten(1)->pluck('item_id')->unique())->pluck('name', 'id');

            foreach ($candidates as $c) {
                $l = ($lines[$c->id] ?? collect())->values();
                if ($l->count() !== 1 || (float) $l[0]->qty < config('security.basket_min_qty')) {
                    continue;
                }

                $g = ['shop_id' => (int) $shop, 'cashier' => (string) ($c->cashier ?? '')];
                $out[] = $this->row('unusual_basket', (float) $c->total >= $cut * config('security.basket_high_multiplier') ? 'high' : 'medium', [
                    'subject' => 'cashier',
                    'shop_id' => (int) $shop,
                    'shop' => $names['shops'][$shop] ?? '#'.$shop,
                    'cashier' => $g['cashier'],
                    'metric' => 'Total of a single-item receipt',
                    'value' => round((float) $c->total, 0),
                    'unit' => 'money',
                    'baseline' => round($cut, 0),
                    'baseline_note' => __('big-receipt cut of the shop (top :pct %)', ['pct' => $this->n(config('security.basket_cut_top_share') * 100, 0)]),
                    'sample' => (int) $n,
                    'finding' => __('One receipt of :total has a single line: :qty x ":item".', ['total' => number_format((float) $c->total, 0, '.', ' '), 'qty' => $this->n((float) $l[0]->qty, 3), 'item' => $itemNames[$l[0]->item_id] ?? '#'.$l[0]->item_id]),
                    'evidence' => __('The shop\'s big-receipt cut is :cut (from :n sale receipts); this receipt is :x times higher.', ['cut' => number_format($cut, 0, '.', ' '), 'n' => (int) $n, 'x' => $this->n((float) $c->total / $cut)]),
                    'receipt_ids' => [(int) $c->id],
                    'evidence_url' => route('receipts.show', ['receipt' => (int) $c->id]),
                ]);
                if (count($out) >= 50) {
                    return $out;
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ drill-down

    /**
     * Numbers of one cashier (in a shop) or one till for the last security.detail_days days against the rest of the chain,
     * by day, plus the off-hours count. The flagged receipt list is flaggedReceipts().
     *
     * @param  array<int, int>  $shopIds  scope of the chain (the viewer's shops)
     * @return array<string, mixed>
     */
    public function detail(array $shopIds, int $shopId, ?string $cashier, ?int $posId, ?Carbon $now = null): array
    {
        $now ??= now();
        $to = $now->copy()->endOfDay();
        $from = $to->copy()->subDays((int) config('security.detail_days') - 1)->startOfDay();

        $subject = fn ($q) => $this->subjectWhere($q, $shopId, $cashier, $posId);
        $totals = fn ($q) => $this->totalsRow($q->first());

        $chain = $totals($this->totalsQuery($from, $to, $shopIds));
        $person = $totals($subject($this->totalsQuery($from, $to, $shopIds)));
        $rest = [];
        foreach ([...self::FIELDS, 'txn_count'] as $f) {
            $rest[$f] = $chain[$f] - $person[$f];
        }

        $metrics = [];
        foreach (self::RATES as $key => $def) {
            $metrics[] = [
                'key' => $key,
                'label' => $this->metricLabel($key),
                'rate' => $person[$def['den']] > 0 ? $person[$def['num']] / $person[$def['den']] * 100 : null,
                'base' => $rest[$def['den']] > 0 ? $rest[$def['num']] / $rest[$def['den']] * 100 : null,
                'events' => (int) $person[$def['events']],
                'sample' => (int) $person[$def['sample']],
                'judged' => $this->judge($key, $person, $chain) !== null,
            ];
        }

        // Off-hours (the shop's own normal hours).
        $profile = $this->hourProfile($to, [$shopId])[$shopId] ?? ['total' => 0, 'hours' => []];
        $offHours = $profile['total'] >= config('security.off_hours_min_baseline_receipts')
            ? array_values(array_filter(range(0, 23), fn ($h) => ($profile['hours'][$h] ?? 0) / $profile['total'] * 100 < config('security.off_hours_share_pct')))
            : [];

        $dayRows = $subject($this->base($from, $to, $shopIds))
            ->groupBy(DB::raw($this->dateExpr()))
            ->selectRaw($this->dateExpr().' as dd,
                SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 THEN 1 ELSE 0 END) as sale_count,
                COALESCE(SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 THEN receipts.total ELSE 0 END), 0) as sale_sum,
                SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 0 THEN 1 ELSE 0 END) as refund_count,
                SUM(CASE WHEN receipts.active = 0 THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND receipts.total + receipts.discount > 0 AND receipts.discount * 100 >= ? * (receipts.total + receipts.discount) THEN 1 ELSE 0 END) as deep_count',
                [(float) config('security.deep_discount_pct')])
            ->get()->keyBy('dd');

        $offByDay = $offHours === [] ? collect() : $subject($this->sales($from, $to, $shopIds))
            ->whereRaw($this->hourExpr().' IN ('.implode(',', $offHours).')')
            ->groupBy(DB::raw($this->dateExpr()))
            ->selectRaw($this->dateExpr().' as dd, COUNT(*) as cnt')->pluck('cnt', 'dd');

        $days = [];
        for ($d = $to->copy(); $d >= $from; $d->subDay()) {
            $date = $d->toDateString();
            $r = $dayRows[$date] ?? null;
            $days[] = [
                'date' => $date,
                'sales' => (int) ($r->sale_count ?? 0),
                'sale_sum' => (float) ($r->sale_sum ?? 0),
                'refunds' => (int) ($r->refund_count ?? 0),
                'cancelled' => (int) ($r->cancelled_count ?? 0),
                'deep' => (int) ($r->deep_count ?? 0),
                'off_hours' => (int) ($offByDay[$date] ?? 0),
            ];
        }

        $names = $this->names($shopIds);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'shop' => $names['shops'][$shopId] ?? '#'.$shopId,
            'pos' => $posId !== null ? ($names['pos'][$posId] ?? '#'.$posId) : null,
            'receipts' => (int) $person['all_count'],
            'sale_count' => (int) $person['sale_count'],
            'sale_sum' => (float) $person['sale_sum'],
            'metrics' => $metrics,
            'off_hours' => ['count' => array_sum(array_column($days, 'off_hours')), 'hours' => $offHours, 'baseline_receipts' => (int) $profile['total']],
            'days' => $days,
            'enough' => $person['all_count'] >= config('security.min_receipts'),
        ];
    }

    /** Query of the receipts worth a look for one subject in the detail window (refunds, cancelled, deep discount, total <= 0). */
    public function flaggedReceipts(array $shopIds, int $shopId, ?string $cashier, ?int $posId, ?Carbon $now = null)
    {
        $now ??= now();
        $to = $now->copy()->endOfDay();
        $from = $to->copy()->subDays((int) config('security.detail_days') - 1)->startOfDay();
        $pct = (float) config('security.deep_discount_pct');

        return $this->subjectWhere($this->base($from, $to, $shopIds), $shopId, $cashier, $posId)
            ->where(function ($q) use ($pct) {
                $q->where('receipts.active', false)
                    ->orWhere(fn ($r) => $r->where('receipts.active', true)->where('receipts.sell', false))
                    ->orWhere(fn ($r) => $r->where('receipts.active', true)->where('receipts.sell', true)->where(function ($s) use ($pct) {
                        $s->where('receipts.total', '<=', 0)->orWhere('receipts.discount', '<', 0)
                            ->orWhereRaw('receipts.total + receipts.discount > 0 AND receipts.discount * 100 >= ? * (receipts.total + receipts.discount)', [$pct]);
                    }));
            })
            ->orderByDesc('receipts.created_at')
            ->select(['receipts.id', 'receipts.number', 'receipts.created_at', 'receipts.total', 'receipts.discount', 'receipts.active', 'receipts.sell', 'receipts.cashier', 'receipts.pos_id']);
    }

    // ------------------------------------------------------------------------------------------------ monitor widgets

    /**
     * Counts only (no rows, no rates) behind the monitor widgets: the totals of the chain and of every cashier of a shop.
     * Uses the same grouped pass as the rate warnings, so after build() for the same dates it costs no extra query.
     * The window is used as given (a live "now" cut is allowed).
     *
     * @return array{chain: array<string, float>, cashiers: array<int, array<string, mixed>>}
     */
    public function figures(Carbon $from, Carbon $to, array $shopIds): array
    {
        if ($shopIds === []) {
            return ['chain' => $this->chain([]), 'cashiers' => []];
        }

        $fine = $this->fine($from, $to, $shopIds);

        return ['chain' => $this->chain($fine), 'cashiers' => array_values($this->grouped($fine, 'cashier'))];
    }

    /** Shops with enough sale receipts in the baseline window to know their normal hours (off-hours and silent-shop checks). */
    public function normalHoursShops(Carbon $to, array $shopIds): int
    {
        if ($shopIds === []) {
            return 0;
        }

        return count(array_filter($this->hourProfile($to, $shopIds), fn ($p) => $p['total'] >= config('security.off_hours_min_baseline_receipts')));
    }

    // ------------------------------------------------------------------------------------------------ queries and helpers

    private function base(Carbon $from, Carbon $to, array $shopIds)
    {
        return ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->whereBetween('receipts.created_at', [$from, $to]);
    }

    private function sales(Carbon $from, Carbon $to, array $shopIds)
    {
        return $this->base($from, $to, $shopIds)->where('receipts.active', true)->where('receipts.sell', true);
    }

    private function hourExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', receipts.created_at) AS INTEGER)"
            : 'HOUR(receipts.created_at)';
    }

    private function dateExpr(): string
    {
        return 'DATE(receipts.created_at)';
    }

    private function deepSql(): string
    {
        return 'receipts.total + receipts.discount > 0 AND receipts.discount * 100 >= ? * (receipts.total + receipts.discount)';
    }

    /** Aggregates of the whole base query (no grouping), as the FIELDS columns. */
    private function totalsQuery(Carbon $from, Carbon $to, array $shopIds)
    {
        return $this->base($from, $to, $shopIds)->selectRaw($this->fieldsSql(), [(float) config('security.deep_discount_pct')]);
    }

    private function fieldsSql(): string
    {
        $paid = 'EXISTS (SELECT 1 FROM receipt_payments rp WHERE rp.receipt_id = receipts.id AND rp.`value` > 0)';
        $noLines = 'NOT EXISTS (SELECT 1 FROM receipt_items ri WHERE ri.receipt_id = receipts.id)';

        return "SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 THEN 1 ELSE 0 END) as sale_count,
            COALESCE(SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 THEN receipts.total ELSE 0 END), 0) as sale_sum,
            SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 0 THEN 1 ELSE 0 END) as refund_count,
            COALESCE(SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 0 THEN ABS(receipts.total) ELSE 0 END), 0) as refund_sum,
            SUM(CASE WHEN receipts.active = 0 THEN 1 ELSE 0 END) as cancelled_count,
            COUNT(*) as all_count,
            SUM(CASE WHEN receipts.active = 1 AND receipts.sell = 1 AND {$this->deepSql()} THEN 1 ELSE 0 END) as deep_count,
            SUM(CASE WHEN receipts.active = 0 AND {$noLines} THEN 1 ELSE 0 END) as empty_count,
            SUM(CASE WHEN receipts.active = 0 AND {$paid} THEN 1 ELSE 0 END) as paid_count";
    }

    private function totalsRow(?object $row): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = (float) ($row->{$f} ?? 0);
        }
        $out['txn_count'] = $out['sale_count'] + $out['refund_count'];

        return $out;
    }

    /**
     * One grouped pass at the finest grain (shop, till, cashier) with every counter; cashier and till groups are sums of it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fine(Carbon $from, Carbon $to, array $shopIds): array
    {
        $key = 'fine|'.$from->format('Ymd').$to->format('Ymd').'|'.implode(',', $shopIds);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $rows = $this->base($from, $to, $shopIds)
            ->groupBy('receipts.shop_id', 'receipts.pos_id', 'receipts.cashier')
            ->selectRaw('receipts.shop_id, receipts.pos_id, receipts.cashier, '.$this->fieldsSql(), [(float) config('security.deep_discount_pct')])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $row = $this->totalsRow($r);
            $row['shop_id'] = (int) $r->shop_id;
            $row['pos_id'] = (int) $r->pos_id;
            $row['cashier'] = (string) ($r->cashier ?? '');
            $k = $row['shop_id'].'|'.$row['pos_id'].'|'.$row['cashier'];
            if (isset($out[$k])) { // NULL and '' cashiers are the same person
                foreach ([...self::FIELDS, 'txn_count'] as $f) {
                    $out[$k][$f] += $row[$f];
                }
            } else {
                $out[$k] = $row;
            }
        }

        return $this->memo[$key] = array_values($out);
    }

    private function chain(array $fine): array
    {
        $chain = array_fill_keys([...self::FIELDS, 'txn_count'], 0.0);
        foreach ($fine as $f) {
            foreach ($chain as $k => $v) {
                $chain[$k] += $f[$k];
            }
        }

        return $chain;
    }

    /** @return array<int|string, array<string, mixed>> */
    private function grouped(array $fine, string $by): array
    {
        $out = [];
        foreach ($fine as $f) {
            $k = $by === 'pos' ? $f['pos_id'] : $f['shop_id'].'|'.$f['cashier'];
            if (! isset($out[$k])) {
                $out[$k] = ['shop_id' => $f['shop_id'], 'pos_id' => $f['pos_id'], 'cashier' => $by === 'pos' ? null : $f['cashier']] + array_fill_keys([...self::FIELDS, 'txn_count'], 0.0);
            }
            foreach ([...self::FIELDS, 'txn_count'] as $c) {
                $out[$k][$c] += $f[$c];
            }
        }

        return $out;
    }

    /** @return array{shops: array<int, string>, pos: array<int, string>} */
    private function names(array $shopIds): array
    {
        $key = 'names|'.implode(',', $shopIds);

        return $this->memo[$key] ??= [
            'shops' => DB::table('shops')->whereIn('id', $shopIds)->pluck('name', 'id')->all(),
            'pos' => DB::table('pos')->whereIn('shop_id', $shopIds)->pluck('name', 'id')->all(),
        ];
    }

    /** Hours of day -> sale receipt counts per shop over the baseline window (28 days ending at $to). */
    private function hourly(Carbon $to, array $shopIds)
    {
        $key = 'hourly|'.$to->format('Ymd').'|'.implode(',', $shopIds);

        return $this->memo[$key] ??= ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->whereBetween('receipts.created_at', [$to->copy()->subDays((int) config('security.baseline_days') - 1)->startOfDay(), $to])
            ->where('receipts.active', true)->where('receipts.sell', true)
            ->groupBy('receipts.shop_id', DB::raw($this->dateExpr()), DB::raw($this->hourExpr()))
            ->selectRaw('receipts.shop_id, '.$this->dateExpr().' as dd, '.$this->hourExpr().' as hh, COUNT(*) as cnt')
            ->get();
    }

    /** @return array<int, array{total: int, hours: array<int, int>}> */
    private function hourProfile(Carbon $to, array $shopIds): array
    {
        $out = [];
        foreach ($this->hourly($to, $shopIds) as $r) {
            $out[(int) $r->shop_id]['total'] = ($out[(int) $r->shop_id]['total'] ?? 0) + (int) $r->cnt;
            $out[(int) $r->shop_id]['hours'][(int) $r->hh] = ($out[(int) $r->shop_id]['hours'][(int) $r->hh] ?? 0) + (int) $r->cnt;
        }

        return $out;
    }

    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Restrict a receipts query to one subject: a till, or a cashier of a shop ('' = no name). */
    private function subjectWhere($q, int $shopId, ?string $cashier, ?int $posId)
    {
        $q->where('receipts.shop_id', $shopId);
        if ($posId !== null) {
            $q->where('receipts.pos_id', $posId);
        } elseif ($cashier !== null) {
            $this->cashierWhere($q, $cashier);
        }

        return $q;
    }

    private function cashierWhere($q, string $cashier)
    {
        return $cashier === ''
            ? $q->where(fn ($w) => $w->whereNull('receipts.cashier')->orWhere('receipts.cashier', ''))
            : $q->where('receipts.cashier', $cashier);
    }

    /** Up to five newest receipt ids of an event kind for a subject. */
    private function eventIds(string $kind, Carbon $from, Carbon $to, array $shopIds, int $shopId, ?string $cashier, ?int $posId): array
    {
        $q = $this->subjectWhere($this->base($from, $to, $shopIds), $shopId, $cashier, $posId);

        match ($kind) {
            'refund' => $q->where('receipts.active', true)->where('receipts.sell', false),
            'cancelled' => $q->where('receipts.active', false),
            'cancelled_empty' => $q->where('receipts.active', false)->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('receipt_items as ri')->whereColumn('ri.receipt_id', 'receipts.id')),
            'cancelled_paid' => $q->where('receipts.active', false)->whereExists(fn ($s) => $s->select(DB::raw(1))->from('receipt_payments as rp')->whereColumn('rp.receipt_id', 'receipts.id')->where('rp.value', '>', 0)),
            'deep' => $q->where('receipts.active', true)->where('receipts.sell', true)->whereRaw($this->deepSql(), [(float) config('security.deep_discount_pct')]),
        };

        return $q->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($id) => (int) $id)->all();
    }

    private function hourIds(Carbon $from, Carbon $to, int $shopId, string $cashier, array $hours): array
    {
        $q = $this->cashierWhere($this->sales($from, $to, [$shopId]), $cashier);

        return $q->whereRaw($this->hourExpr().' IN ('.implode(',', array_map('intval', $hours)).')')
            ->orderByDesc('receipts.created_at')->limit(5)->pluck('receipts.id')->map(fn ($id) => (int) $id)->all();
    }

    private function subjectUrl(string $subject, array $g): string
    {
        return $subject === 'till'
            ? route('security.cashier', ['shop' => $g['shop_id'], 'pos' => $g['pos_id']])
            : route('security.cashier', ['shop' => $g['shop_id'], 'cashier' => $g['cashier'] ?? '']);
    }

    private function who(string $subject, array $g, array $names): string
    {
        $shop = $names['shops'][$g['shop_id']] ?? '#'.$g['shop_id'];

        return $subject === 'till'
            ? $shop.' / '.__('till').' '.($names['pos'][$g['pos_id']] ?? '#'.$g['pos_id'])
            : $shop.' / '.(filled($g['cashier']) ? $g['cashier'] : __('Unknown cashier'));
    }

    private function steps(int $count, int $medium, int $high): string
    {
        return $count >= $high ? 'high' : ($count >= $medium ? 'medium' : 'low');
    }

    private function n(float|int $v, int $d = 1): string
    {
        return number_format((float) $v, $d, '.', ' ');
    }

    /** Fills the common row shape. */
    private function row(string $type, string $severity, array $f): array
    {
        return $f + [
            'subject' => 'shop',
            'cashier' => null,
            'pos_id' => null,
            'pos' => null,
            'baseline' => null,
            'baseline_note' => null,
            'receipt_ids' => [],
        ] + ['type' => $type, 'severity' => $severity];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function kpi(array $rows): array
    {
        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['severity'] === $s));

        return [
            'total' => count($rows),
            'high' => $count('high'),
            'medium' => $count('medium'),
            'low' => $count('low'),
            'shops' => count(array_unique(array_column($rows, 'shop_id'))),
            'cashiers' => count(array_unique(array_map(fn ($r) => $r['shop_id'].'|'.$r['cashier'], array_filter($rows, fn ($r) => $r['cashier'] !== null)))),
            'tills' => count(array_unique(array_filter(array_column($rows, 'pos_id'), fn ($p) => $p !== null))),
        ];
    }
}
