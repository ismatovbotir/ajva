<?php

namespace App\Services;

use App\Support\ReceiptChecks;
use App\Support\ReceiptsSync;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Receipt data health (Monitoring > Data health, and the data-health monitor widgets): is the feed that the forecast
 * and the order quantities depend on complete and clean? Live, cached 60 s with the sales version.
 *
 * Metric dictionary
 * -----------------
 * last receipt / lag   newest receipt time per shop (not in the future); lag = now - that time (hours). Amber / red after
 *                      receipts.health.lag_amber_hours / lag_red_hours (a shop sleeps at night, so these are long).
 * 24 h vs usual        sale receipts (active and sell) in the last 24 h against the USUAL DAY of the shop = the median of its
 *                      completed days of the last 28 (from its first receipt in that window; needs 7 such days). ratio below
 *                      inventory.feed_silence_min_ratio (0.2) = silent (red), below receipts.health.low_ratio (0.5) = amber.
 *                      Shops whose usual day is under receipts.health.min_usual (20) receipts are not judged ("n/a").
 * tills active         distinct tills with a receipt in the last 24 h / tills registered for the shop.
 * rejections           rejected receipts (till 422 and history batch) in the last 24 h by reason, the unknown item ids behind
 *                      them (top 5) and the daily counts of the last 7 days. A spike = at least receipts.health.spike_min in
 *                      24 h and receipts.health.spike_ratio x the average of the 6 days before.
 * late / backfilled    till receipts first stored in the last 24 h / 7 days whose own time is more than receipts.late_hours before
 *                      their ingestion time (receipts.ingested_at; rows from before it was recorded have none; 1C history excluded).
 * data flags           receipts of the last 7 days carrying receipts.dq_flags, by code.
 * history cursor       the 1C history cursor (receipt time) and the age of the newest batch.
 * freshness            newest 1C stock date next to the newest receipt: stock age is counted from the END of the stock date.
 * The main warehouse shop is left out (it sells nothing). Needs no cost data.
 */
class DataHealth
{
    /** @param  array<int, int>|null  $shopIds  null = every shop */
    public function cached(?array $shopIds, ?Carbon $now = null): array
    {
        $now ??= now();

        return Cache::remember(
            ShopAccess::salesKey('data-health', $now->toDateString(), $shopIds),
            max(1, (int) config('receipts.health.cache_seconds', 60)),
            fn () => $this->build($shopIds, $now),
        );
    }

    /**
     * @param  array<int, int>|null  $shopIds  null = every shop
     * @return array<string, mixed>
     */
    public function build(?array $shopIds, ?Carbon $now = null): array
    {
        $now ??= now();
        $h = (array) config('receipts.health', []);

        $warehouse = Warehouse::shopId();
        $shops = ShopAccess::restrictTo(DB::table('shops'), 'shops.id', $shopIds)
            ->when($warehouse !== null, fn ($q) => $q->where('shops.id', '!=', $warehouse))
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);
        $scope = $shops->pluck('id')->map(fn ($id) => (int) $id)->all();

        $out = [
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'shops' => [],
            'silent_count' => 0,
            'warn_count' => 0,
            'rejections' => $this->rejections($shopIds, $now, $h),
            'late' => $this->late($shopIds, $now),
            'flags' => $this->flags($shopIds, $now),
            'cursor' => $this->cursor($now),
            'freshness' => $this->freshness($shopIds, $now),
        ];

        if ($scope === []) {
            return $out;
        }

        $last = DB::table('receipts')->whereIn('shop_id', $scope)->where('created_at', '<=', $now)
            ->groupBy('shop_id')->selectRaw('shop_id, MAX(created_at) as last_at')->pluck('last_at', 'shop_id');

        $day = DB::table('receipts')->whereIn('shop_id', $scope)->where('active', true)->where('sell', true)
            ->where('created_at', '>', $now->copy()->subDay())->where('created_at', '<=', $now)
            ->groupBy('shop_id')->selectRaw('shop_id, COUNT(*) as n, COUNT(DISTINCT pos_id) as tills')->get()->keyBy('shop_id');

        $registered = DB::table('pos')->whereIn('shop_id', $scope)->groupBy('shop_id')->selectRaw('shop_id, COUNT(*) as n')->pluck('n', 'shop_id');

        $daily = $this->dailyCounts($scope, $now);
        $minUsual = (int) ($h['min_usual'] ?? 20);
        $silentRatio = (float) config('inventory.feed_silence_min_ratio', 0.2);
        $lowRatio = (float) ($h['low_ratio'] ?? 0.5);

        foreach ($shops as $shop) {
            $id = (int) $shop->id;
            $lastAt = $last[$id] ?? null;
            $lag = $lastAt === null ? null : max(0.0, Carbon::parse($lastAt)->diffInMinutes($now, false) / 60);
            $usual = $this->usualDay($daily[$id] ?? []);
            $count = (int) ($day[$id]->n ?? 0);

            $status = 'na';
            $ratio = null;
            if ($usual !== null && $usual >= $minUsual) {
                $ratio = $count / $usual;
                $status = $ratio < $silentRatio ? 'bad' : ($ratio < $lowRatio ? 'warn' : 'ok');
            }
            $lagStatus = 'ok';
            if ($lag === null) {
                $lagStatus = 'na';
            } elseif ($status !== 'na') {
                $lagStatus = $lag > (float) ($h['lag_red_hours'] ?? 24) ? 'bad' : ($lag > (float) ($h['lag_amber_hours'] ?? 12) ? 'warn' : 'ok');
            }

            $out['shops'][] = [
                'id' => $id,
                'name' => (string) $shop->name,
                'last_at' => $lastAt === null ? null : substr((string) $lastAt, 0, 19),
                'lag_hours' => $lag === null ? null : round($lag, 1),
                'lag_status' => $lagStatus,
                'last24' => $count,
                'usual' => $usual === null ? null : round($usual, 0),
                'ratio' => $ratio === null ? null : round($ratio, 2),
                'status' => $status,
                'silent' => $status === 'bad',
                'tills_active' => (int) ($day[$id]->tills ?? 0),
                'tills_registered' => (int) ($registered[$id] ?? 0),
            ];
            if ($status === 'bad') {
                $out['silent_count']++;
            } elseif ($status === 'warn') {
                $out['warn_count']++;
            }
        }

        return $out;
    }

    /**
     * Sale receipts per completed day for the last 28 days: shop id => [date => count], with zero-count days filled in from
     * the shop's first receipt of the window.
     *
     * @param  array<int, int>  $scope
     * @return array<int, array<string, int>>
     */
    private function dailyCounts(array $scope, Carbon $now): array
    {
        $today = $now->copy()->startOfDay();
        $rows = DB::table('receipts')->whereIn('shop_id', $scope)->where('active', true)->where('sell', true)
            ->where('created_at', '>=', $today->copy()->subDays(28))->where('created_at', '<', $today)
            ->groupBy('shop_id', 'd')->selectRaw('shop_id, DATE(created_at) as d, COUNT(*) as n')->get();

        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r->shop_id][(string) $r->d] = (int) $r->n;
        }

        $out = [];
        foreach ($by as $shopId => $days) {
            $first = min(array_keys($days));
            for ($i = 28; $i >= 1; $i--) {
                $date = $today->copy()->subDays($i)->toDateString();
                if ($date >= $first) {
                    $out[$shopId][$date] = $days[$date] ?? 0;
                }
            }
        }

        return $out;
    }

    /** @param  array<string, int>  $days */
    private function usualDay(array $days): ?float
    {
        if (count($days) < 7) {
            return null;
        }
        $v = array_values($days);
        sort($v);
        $n = count($v);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2;
    }

    /**
     * @param  array<int, int>|null  $shopIds
     * @param  array<string, mixed>  $h
     * @return array<string, mixed>
     */
    private function rejections(?array $shopIds, Carbon $now, array $h): array
    {
        $since = $now->copy()->subDay();
        $rows = fn () => ShopAccess::restrictTo(DB::table('receipt_rejections'), 'shop_id', $shopIds);

        $by = $rows()->where('created_at', '>', $since)->groupBy('reason')->selectRaw('reason, COUNT(*) as n')->pluck('n', 'reason');
        $items = $rows()->where('created_at', '>', $since)->where('reason', 'unknown_item')->whereNotNull('item_id')
            ->groupBy('item_id')->selectRaw('item_id, COUNT(*) as n')->orderByDesc('n')->orderBy('item_id')->limit(5)->get()
            ->map(fn ($r) => ['item_id' => (int) $r->item_id, 'count' => (int) $r->n])->all();

        // Daily counts of the last 7 days: the counter table when the viewer sees the whole chain, else the (capped) rows.
        $from = $now->copy()->startOfDay()->subDays(6);
        $daily = [];
        if ($shopIds === null) {
            foreach (DB::table('receipt_rejection_counts')->where('stat_date', '>=', $from->toDateString())->get() as $r) {
                $daily[substr((string) $r->stat_date, 0, 10)][(string) $r->reason] = (int) $r->total;
            }
        } else {
            foreach ($rows()->where('created_at', '>=', $from)->groupBy('d', 'reason')->selectRaw('DATE(created_at) as d, reason, COUNT(*) as n')->get() as $r) {
                $daily[(string) $r->d][(string) $r->reason] = (int) $r->n;
            }
        }
        ksort($daily);

        $total = (int) array_sum($by->all());
        $today = $now->toDateString();
        $earlier = [];
        for ($i = 1; $i <= 6; $i++) {
            $earlier[] = (int) array_sum($daily[$now->copy()->subDays($i)->toDateString()] ?? []);
        }
        $avg = array_sum($earlier) / 6;
        $spike = $total >= (int) ($h['spike_min'] ?? 10) && $total > (float) ($h['spike_ratio'] ?? 3.0) * $avg;

        return [
            'total24' => $total,
            'by_reason' => $by->map(fn ($n) => (int) $n)->sortDesc()->all(),
            'top_items' => $items,
            'daily' => $daily,
            'today' => $today,
            'spike' => $spike,
            'rows_kept' => (int) $rows()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function late(?array $shopIds, Carbon $now): array
    {
        $hours = (int) config('receipts.late_hours', 48);
        $q = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->where('receipts.source', 'pos')->where('receipts.ingested_at', '>', $now->copy()->subDays(7));
        $late = ReceiptChecks::lateSql();
        $r = $q->selectRaw('COUNT(*) as arrived7, SUM(CASE WHEN '.$late.' THEN 1 ELSE 0 END) as late7, '
            .'SUM(CASE WHEN '.$late.' AND receipts.ingested_at > ? THEN 1 ELSE 0 END) as late24', [$hours, $hours, $now->copy()->subDay()])->first();

        return [
            'hours' => $hours,
            'arrived7' => (int) ($r->arrived7 ?? 0),
            'late7' => (int) ($r->late7 ?? 0),
            'late24' => (int) ($r->late24 ?? 0),
            'tracked_since' => ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)->min('ingested_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function flags(?array $shopIds, Carbon $now): array
    {
        $rows = ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)
            ->where('created_at', '>=', $now->copy()->subDays(7))->where('created_at', '<=', $now)
            ->whereNotNull('dq_flags')->where('dq_flags', '<>', '')
            ->groupBy('dq_flags')->selectRaw('dq_flags, COUNT(*) as n')->get();

        $codes = [];
        $receipts = 0;
        foreach ($rows as $r) {
            $receipts += (int) $r->n;
            foreach (array_filter(explode(',', (string) $r->dq_flags)) as $code) {
                $codes[$code] = ($codes[$code] ?? 0) + (int) $r->n;
            }
        }
        arsort($codes);

        return ['receipts' => $receipts, 'codes' => $codes];
    }

    /** @return array<string, mixed> */
    private function cursor(Carbon $now): array
    {
        $state = ReceiptsSync::state();
        $batchAt = $state['last_batch']['at'] ?? null;

        return [
            'last_receipt_at' => $state['last_receipt_at'],
            'last_batch_at' => $batchAt,
            'batch_age_hours' => $batchAt === null ? null : round(max(0.0, Carbon::parse($batchAt)->diffInMinutes($now, false) / 60), 1),
        ];
    }

    /**
     * Stock snapshot age next to the sales feed age. stock_date is a day: counted from the end of that day.
     *
     * @param  array<int, int>|null  $shopIds
     * @return array<string, mixed>
     */
    private function freshness(?array $shopIds, Carbon $now): array
    {
        $stock = ShopAccess::restrictTo(DB::table('stocks'), 'stocks.shop_id', $shopIds)->max('stocks.stock_date');
        $stock = $stock === null ? null : substr((string) $stock, 0, 10);
        $receipt = ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)->where('created_at', '<=', $now)->max('created_at');

        return [
            'stock_date' => $stock,
            'stock_hours' => $stock === null ? null : round(max(0.0, Carbon::parse($stock)->endOfDay()->diffInMinutes($now, false) / 60), 1),
            'receipt_at' => $receipt === null ? null : substr((string) $receipt, 0, 19),
            'receipt_hours' => $receipt === null ? null : round(max(0.0, Carbon::parse($receipt)->diffInMinutes($now, false) / 60), 1),
        ];
    }
}
