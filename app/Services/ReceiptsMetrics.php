<?php

namespace App\Services;

use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receipts analytics for the "receipts" monitor: today's receipts picture of a
 * set of shops against yesterday UP TO THE SAME TIME OF DAY.
 *
 * Metric dictionary
 * -----------------
 * sale receipt     receipts.active = 1 AND sell = 1
 * refund receipt   active = 1 AND sell = 0 (amount = SUM(ABS(total)))
 * cancelled        active = 0 (amount = SUM(ABS(total)))
 * receipts         number of sale receipts (refunds / cancelled are NOT in it)
 * sales total      SUM(total) of sale receipts
 * average check    sales total / receipts
 * basket size      positions (receipt_items, storno = 0) of sale receipts / receipts - lines, not
 *                  quantities, so kg/litre items do not distort it
 * refund share     refund receipts / all receipts (sale + refund + cancelled), plus refund amount as % of sales total
 * cancelled share  cancelled receipts / all receipts
 * severity         share >= 5 % red, >= 2 % amber, only when there are at least MIN_BASE receipts today
 *                  (one refund among three receipts is noise, not an alarm); refunds are judged by amount
 *                  share, cancelled by count share
 * peak hour        hour of day with the most sale receipts today
 * busiest / quietest shop   most / fewest sale receipts today among the shown shops (a shop without a sale is
 *                  the quietest; needs two or more shops)
 * tills active     distinct POS terminals with an active receipt in the last 30 minutes / POS terminals of the shops
 * baseline         same shops, yesterday from 00:00 up to the current time of day
 *
 * Everything is aggregated in SQL (one grouped query per question) over receipts for two days:
 * index receipts(created_at) / receipts(shop_id, created_at); at 66k receipts/day this is a range of ~130k
 * rows per refresh, cached by the caller for ~25 s and invalidated by the sales.version counter.
 */
class ReceiptsMetrics
{
    public const TICKER_ROWS = 9;

    public const TILL_WINDOW_MINUTES = 30;

    public const MIN_BASE = 10;

    public const WARN_PERCENT = 2.0;

    public const HIGH_PERCENT = 5.0;

    /**
     * @param  array<int, int>|null  $shopIds  null = every shop, [] = none
     * @return array<string, mixed>
     */
    public function board(Carbon $now, ?array $shopIds = null, bool $withProfit = false): array
    {
        $todayStart = $now->copy()->startOfDay();
        $yStart = $todayStart->copy()->subDay();
        $yCut = $now->copy()->subDay();

        $shops = ShopAccess::restrictTo(DB::table('shops')->orderBy('name')->orderBy('id'), 'id', $shopIds)->pluck('name', 'id')->all();

        [$perShop, $lines] = $this->perShop($todayStart, $now, $yStart, $yCut, $shopIds);

        $rows = [];
        $total = [1 => $this->blank(), 0 => $this->blank()];
        foreach ($shops as $id => $name) {
            $id = (int) $id;
            $row = ['id' => $id, 'name' => (string) $name];
            foreach ([1 => 'today', 0 => 'yesterday'] as $w => $key) {
                $cell = $this->blank();
                foreach (['s', 'r', 'c'] as $k) {
                    $cell[$k] = $perShop[$id][$w][$k] ?? ['count' => 0, 'sum' => 0.0];
                    $total[$w][$k]['count'] += $cell[$k]['count'];
                    $total[$w][$k]['sum'] += $cell[$k]['sum'];
                }
                $cell['lines'] = $lines[$id][$w] ?? 0;
                $total[$w]['lines'] += $cell['lines'];
                $row[$key] = $this->derive($cell);
            }
            $row['count_delta'] = $this->delta($row['today']['count'], $row['yesterday']['count']);
            $row['sum_delta'] = $this->delta($row['today']['sum'], $row['yesterday']['sum']);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => [$b['today']['sum'], $b['today']['count'], $a['name']] <=> [$a['today']['sum'], $a['today']['count'], $b['name']]);

        $today = $this->derive($total[1]);
        $yesterday = $this->derive($total[0]);

        $hourly = $this->hourly($todayStart, $now, $yStart, $yCut, $shopIds);

        $board = [
            'asOf' => $now->format('H:i'),
            'today' => $today,
            'yesterday' => $yesterday,
            'deltas' => [
                'count' => $this->deltaPair($today['count'], $yesterday['count']),
                'sum' => $this->deltaPair($today['sum'], $yesterday['sum']),
                'avg' => $this->deltaPair($today['avg'], $yesterday['avg']),
                'basket' => $this->deltaPair($today['basket'], $yesterday['basket']),
            ],
            'refundSeverity' => $this->severity($today['refund_amount_percent'], $today['all']),
            'cancelledSeverity' => $this->severity($today['cancelled_percent'], $today['all']),
            'shops' => $rows,
            'busiest' => null,
            'quietest' => null,
            'hours' => $hourly['hours'],
            'peak' => $hourly['peak'],
            'payments' => app(SalesMetrics::class)->paymentMix($todayStart, $now, $shopIds),
            'latest' => $this->latest($todayStart, $now, $shopIds),
            'tills' => $this->tills($now, $shopIds),
        ];

        if (count($rows) >= 2) {
            $byCount = $rows;
            usort($byCount, fn ($a, $b) => [$b['today']['count'], $a['name']] <=> [$a['today']['count'], $b['name']]);
            if ($byCount[0]['today']['count'] > 0) {
                $board['busiest'] = ['name' => $byCount[0]['name'], 'count' => $byCount[0]['today']['count']];
            }
            $last = $byCount[count($byCount) - 1];
            if ($last['name'] !== ($board['busiest']['name'] ?? null)) {
                $board['quietest'] = ['name' => $last['name'], 'count' => $last['today']['count']];
            }
        }

        if ($withProfit) {
            $names = $shops;
            $board['profit'] = app(SalesMetrics::class)->profitSummary(
                $todayStart, $now, $yStart, $yCut,
                $names, array_fill_keys(array_keys($names), SalesMetrics::COLOR_OTHER), $shopIds,
            );
        }

        return $board;
    }

    /** 'high' | 'warn' | 'ok' for a share in percent; small bases never alarm. */
    public function severity(float $percent, int $base): string
    {
        if ($base < self::MIN_BASE) {
            return 'ok';
        }

        return $percent >= self::HIGH_PERCENT ? 'high' : ($percent >= self::WARN_PERCENT ? 'warn' : 'ok');
    }

    public function delta(float|int $current, float|int $previous): ?float
    {
        return $previous > 0 ? ($current - $previous) / $previous * 100 : null;
    }

    /** @return array{pct: ?float, diff: float} percent (null without a base) and the absolute difference */
    private function deltaPair(float|int $current, float|int $previous): array
    {
        return ['pct' => $this->delta($current, $previous), 'diff' => (float) $current - (float) $previous];
    }

    /** @return array<string, mixed> */
    private function blank(): array
    {
        return ['s' => ['count' => 0, 'sum' => 0.0], 'r' => ['count' => 0, 'sum' => 0.0], 'c' => ['count' => 0, 'sum' => 0.0], 'lines' => 0];
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function derive(array $t): array
    {
        $count = (int) $t['s']['count'];
        $sum = (float) $t['s']['sum'];
        $all = $count + (int) $t['r']['count'] + (int) $t['c']['count'];

        return [
            'count' => $count,
            'sum' => $sum,
            'avg' => $count > 0 ? $sum / $count : 0.0,
            'basket' => $count > 0 ? $t['lines'] / $count : 0.0,
            'refund_count' => (int) $t['r']['count'],
            'refund_sum' => (float) $t['r']['sum'],
            'refund_percent' => $all > 0 ? $t['r']['count'] / $all * 100 : 0.0,
            'refund_amount_percent' => $sum > 0 ? $t['r']['sum'] / $sum * 100 : 0.0,
            'cancelled_count' => (int) $t['c']['count'],
            'cancelled_sum' => (float) $t['c']['sum'],
            'cancelled_percent' => $all > 0 ? $t['c']['count'] / $all * 100 : 0.0,
            'all' => $all,
        ];
    }

    /** Adds the two comparison windows to a query on receipts (qualified column). */
    private function windows($query, string $column, Carbon $todayStart, Carbon $now, Carbon $yStart, Carbon $yCut)
    {
        return $query->where(fn ($q) => $q
            ->whereBetween($column, [$todayStart, $now])
            ->orWhereBetween($column, [$yStart, $yCut]));
    }

    /**
     * Receipts per shop, window (1 today, 0 yesterday-to-same-time) and kind (s sale, r refund, c cancelled),
     * and the positions of the sale receipts.
     *
     * @return array{0: array<int, array<int, array<string, array{count: int, sum: float}>>>, 1: array<int, array<int, int>>}
     */
    private function perShop(Carbon $todayStart, Carbon $now, Carbon $yStart, Carbon $yCut, ?array $shopIds): array
    {
        $rows = $this->windows(ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds), 'created_at', $todayStart, $now, $yStart, $yCut)
            ->groupBy('shop_id', 'w', 'k')
            ->selectRaw(
                "shop_id, CASE WHEN created_at >= ? THEN 1 ELSE 0 END AS w, CASE WHEN active = 0 THEN 'c' WHEN sell = 1 THEN 's' ELSE 'r' END AS k, "
                .'COUNT(*) AS c, SUM(CASE WHEN active = 1 AND sell = 1 THEN total ELSE ABS(total) END) AS s',
                [$todayStart]
            )
            ->get();

        $perShop = [];
        foreach ($rows as $r) {
            $perShop[(int) $r->shop_id][(int) $r->w][(string) $r->k] = ['count' => (int) $r->c, 'sum' => (float) $r->s];
        }

        $lineRows = $this->windows(
            ShopAccess::restrictTo(DB::table('receipt_items as ri')->join('receipts', 'receipts.id', '=', 'ri.receipt_id'), 'receipts.shop_id', $shopIds),
            'receipts.created_at', $todayStart, $now, $yStart, $yCut
        )
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->where('ri.storno', false)
            ->groupBy('receipts.shop_id', 'w')
            ->selectRaw('receipts.shop_id AS shop_id, CASE WHEN receipts.created_at >= ? THEN 1 ELSE 0 END AS w, COUNT(*) AS n', [$todayStart])
            ->get();

        $lines = [];
        foreach ($lineRows as $r) {
            $lines[(int) $r->shop_id][(int) $r->w] = (int) $r->n;
        }

        return [$perShop, $lines];
    }

    /**
     * Sale receipts per hour: today and yesterday up to the same time (so the current hour compares part with part).
     * Only the hours that matter are returned: from the first hour with a sale (today or yesterday) to the
     * current hour or the last hour with a sale, at least 8 columns.
     *
     * @return array{hours: array<int, array<string, mixed>>, peak: ?array{hour: string, count: int}}
     */
    private function hourly(Carbon $todayStart, Carbon $now, Carbon $yStart, Carbon $yCut, ?array $shopIds): array
    {
        $hourExpr = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $rows = $this->windows(ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds), 'created_at', $todayStart, $now, $yStart, $yCut)
            ->where('active', true)
            ->where('sell', true)
            ->groupBy('w', 'hr')
            ->selectRaw("CASE WHEN created_at >= ? THEN 1 ELSE 0 END AS w, {$hourExpr} AS hr, COUNT(*) AS c", [$todayStart])
            ->get();

        $today = array_fill(0, 24, 0);
        $yesterday = array_fill(0, 24, 0);
        foreach ($rows as $r) {
            if ($r->w) {
                $today[(int) $r->hr] = (int) $r->c;
            } else {
                $yesterday[(int) $r->hr] = (int) $r->c;
            }
        }

        $current = (int) $now->format('G');
        $active = array_values(array_filter(range(0, 23), fn ($h) => $today[$h] > 0 || $yesterday[$h] > 0));
        $from = $active === [] ? max($current - 7, 0) : min($active);
        $to = $active === [] ? $current : max(max($active), $current);
        $to = min($to, 23);
        $from = max(min($from, $current), 0);
        if ($to - $from < 7) {
            $from = max($to - 7, 0);
            $to = min($from + 7, 23);
        }

        $max = max(1, ...array_values($today), ...array_values($yesterday));
        $hours = [];
        for ($h = $from; $h <= $to; $h++) {
            $hours[] = [
                'hour' => sprintf('%02d', $h),
                'count' => $today[$h],
                'y_count' => $yesterday[$h],
                'height' => $today[$h] / $max * 100,
                'marker' => $yesterday[$h] / $max * 100,
                'current' => $h === $current,
                'future' => $h > $current,
            ];
        }

        $peakHour = array_keys($today, max($today))[0];

        return [
            'hours' => $hours,
            'peak' => max($today) > 0 ? ['hour' => sprintf('%02d', $peakHour), 'count' => $today[$peakHour]] : null,
        ];
    }

    /**
     * Newest receipts of the day, refunds and cancelled ones flagged.
     *
     * @return array<int, array<string, mixed>>
     */
    private function latest(Carbon $from, Carbon $to, ?array $shopIds): array
    {
        $rows = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $shopIds)
            ->join('shops', 'shops.id', '=', 'receipts.shop_id')
            ->leftJoin('pos', 'pos.id', '=', 'receipts.pos_id')
            ->whereBetween('receipts.created_at', [$from, $to])
            ->orderByDesc('receipts.created_at')
            ->orderByDesc('receipts.id')
            ->limit(self::TICKER_ROWS)
            ->get(['receipts.id', 'receipts.created_at', 'receipts.total', 'receipts.sell', 'receipts.active', 'shops.name as shop_name', 'pos.name as pos_name']);

        $payments = DB::table('receipt_payments')
            ->whereIn('receipt_id', $rows->pluck('id'))
            ->orderByDesc('value')
            ->get(['receipt_id', 'payment'])
            ->groupBy('receipt_id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'time' => Carbon::parse($r->created_at)->format('H:i:s'),
            'shop' => (string) $r->shop_name,
            'pos' => (string) ($r->pos_name ?? ''),
            'total' => abs((float) $r->total),
            'cancelled' => ! $r->active,
            'refund' => (bool) $r->active && ! $r->sell,
            'payment' => ($payments[$r->id] ?? collect())->pluck('payment')->unique()->implode(', '),
        ])->all();
    }

    /**
     * Tills (POS terminals) with an active receipt in the last 30 minutes, out of all terminals of the shops.
     *
     * @return array{active: int, total: int}
     */
    private function tills(Carbon $now, ?array $shopIds): array
    {
        $active = (int) ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $shopIds)
            ->where('active', true)
            ->whereBetween('created_at', [$now->copy()->subMinutes(self::TILL_WINDOW_MINUTES), $now])
            ->distinct()
            ->count('pos_id');

        $total = (int) ShopAccess::restrictTo(DB::table('pos'), 'shop_id', $shopIds)->count();

        return ['active' => $active, 'total' => max($total, $active)];
    }
}
