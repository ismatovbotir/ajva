<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Stock;
use App\Models\User;
use App\Monitor\Comparison;
use App\Support\ProfitAccess;
use App\Support\ShopAccess;
use App\Support\TaskRuns;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Needs attention" rows of the Home page: what is wrong right now, most severe first, at most MAX rows, each
 * {key, severity red|amber, icon, text, count, url}. Everything reuses existing services and thresholds (nothing is
 * invented here) and is set-based; the result is cached TTL seconds per role, ShopAccess scope, profit variant, day
 * and sales version, so two viewers with the same scope share one computation.
 *
 * Sources and thresholds
 * ----------------------
 * all roles (an operator only sees his own shops):
 *   drafts            order drafts nobody confirmed: amber from 2 h, red from 24 h (UnconfirmedOrders constants)
 *   receipt_overdue   confirmed orders the shop did not receive by the delivery deadline (OrderReceiving), or expired: red
 *   stockouts         ruled (item, shop) pairs (min > 0) REPORTED by the latest 1C sync with qty <= 0 (explicit zero
 *                     rule: a pair the sync did not report is not out of stock): amber, red above 5 % of ruled pairs
 *   not_reported      ruled pairs the latest sync did not report (unknown, not zero): amber
 *   sales_drop        today's sales against yesterday / the same weekday last week at the same time of day, with the
 *                     monitors' Comparison rule (a baseline needs >= 10 receipts; < -5 % amber, < -15 % red)
 *   refunds / cancelled   refund amount share of sales and cancelled share of receipts today, Comparison::rateStatus
 *                     (>= 10 receipts today; 2 % amber, 5 % red)
 * admin only:
 *   approval          orders sent by a shop with exceptions, waiting for an admin: amber from 2 h, red from 24 h
 *   sync_failed       confirmed orders whose 1C sync failed: red
 *   telegram          drafts whose Telegram message failed / had no recipient / no bot: amber
 *   wh_not_synced     the main warehouse stock is older than the other shops' sync: red
 *   wh_out            items out of stock at the main warehouse: amber, red when a class A item is among them
 *   wh_starved / wh_short   shops that are out of stock and get nothing from the warehouse (red); items the warehouse
 *                     cannot cover for the suggested orders (amber)
 *   security          HIGH security warnings of the last day (own 10 minute cache: the report is heavy): red
 *   silent_shops      shops whose feed is silent (DataHealth, 24 h against the usual day): red
 *   scheduler / queue / failed_jobs   scheduler heartbeat older than 3 min (red), a queue that has waited > 2 min
 *                     (red), failed jobs (amber)
 * There is no profit-only row (the profit variant is still part of the cache key, so a profit row can be added
 * without a leak). Never a cost, profit or cashier name.
 */
class AttentionItems
{
    public const MAX = 8;

    public const TTL = 60;

    /** Share of ruled pairs out of stock above which the stock-out row turns red. */
    public const STOCKOUT_RED_PERCENT = 5.0;

    /** @return array<int, array{key: string, severity: string, icon: string, text: string, count: int, url: string}> */
    public function for(User $user, ?Carbon $now = null): array
    {
        return $this->report($user, $now)['rows'];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, facts: array<string, mixed>, checked_at: string}
     */
    public function report(User $user, ?Carbon $now = null): array
    {
        $now ??= now();
        $role = $user->role?->value;
        if (! in_array($role, ['admin', 'operator'], true)) {
            return ['rows' => [], 'facts' => [], 'checked_at' => $now->format('H:i')];
        }

        $ids = ShopAccess::ids($user);
        $key = ShopAccess::salesKey('attention', $role.'.'.ProfitAccess::profitKey(ProfitAccess::allowed($user)).'.'.$now->toDateString(), $ids);

        return Cache::remember($key, self::TTL, fn () => $this->compute($user->isAdmin(), $ids, $now));
    }

    /**
     * @param  array<int, int>|null  $ids  shop scope (null = every shop)
     * @return array{rows: array<int, array<string, mixed>>, facts: array<string, mixed>, checked_at: string}
     */
    private function compute(bool $admin, ?array $ids, Carbon $now): array
    {
        $rows = [];
        $facts = [];
        $add = function (string $key, string $severity, string $icon, string $text, int $count, string $url) use (&$rows) {
            $rows[] = ['key' => $key, 'severity' => $severity, 'icon' => $icon, 'text' => $text, 'count' => $count, 'url' => $url];
        };
        $sev = fn (string $status) => $status === 'bad' ? 'red' : ($status === 'warn' ? 'amber' : null);

        if ($ids === []) {
            return ['rows' => [], 'facts' => ['drafts' => 0], 'checked_at' => $now->format('H:i')];
        }

        $orders = fn () => ShopAccess::restrictTo(Order::query(), 'orders.shop_id', $ids);

        // ---- drafts nobody confirmed
        $drafts = $orders()->where('status', OrderStatus::Draft->value)->get(['id', 'shop_id', 'created_at', 'telegram_status']);
        $old = $drafts->filter(fn (Order $o) => $o->created_at->lte($now->copy()->subMinutes(UnconfirmedOrders::DRAFT_AMBER_MINUTES)));
        $veryOld = $old->filter(fn (Order $o) => $o->created_at->lte($now->copy()->subMinutes(UnconfirmedOrders::DRAFT_RED_MINUTES)));
        $facts['drafts'] = $drafts->count();
        $facts['drafts_late'] = $old->count();
        if ($old->isNotEmpty()) {
            $add('drafts', $veryOld->isNotEmpty() ? 'red' : 'amber', 'clipboard',
                $veryOld->isNotEmpty()
                    ? __(':count order drafts are not confirmed yet, the oldest for more than a day.', ['count' => $old->count()])
                    : __(':count order drafts are not confirmed for more than 2 hours.', ['count' => $old->count()]),
                $old->count(), route('monitoring.unconfirmed-orders', ['type' => 'draft']));
        }

        // ---- confirmed orders not received on time
        $late = $orders()->where('status', OrderStatus::Confirmed->value)->whereNull('received_at')->get()
            ->filter(fn (Order $o) => OrderReceiving::isOverdue($o, $now) || $o->close_reason === Order::CLOSE_EXPIRED);
        $facts['overdue_receipts'] = $late->count();
        if ($late->isNotEmpty()) {
            $add('receipt_overdue', 'red', 'truck', __(':count confirmed orders were not received on time.', ['count' => $late->count()]),
                $late->count(), route('monitoring.unconfirmed-orders', ['type' => 'receipt']));
        }

        if ($admin) {
            // ---- waiting for an admin's approval
            $waiting = $orders()->where('status', OrderStatus::PendingApproval->value)->where('review_status', Order::REVIEW_PENDING)
                ->get(['id', 'created_at', 'updated_at']);
            $facts['approval'] = $waiting->count();
            if ($waiting->isNotEmpty()) {
                $sent = OrderEvent::query()->whereIn('order_id', $waiting->pluck('id'))->where('action', OrderEvent::SENT_FOR_APPROVAL)
                    ->get(['order_id', 'created_at'])->groupBy('order_id')->map(fn ($g) => $g->max('created_at'));
                $ages = $waiting->map(fn (Order $o) => (int) ($sent->get($o->id) ?? $o->updated_at ?? $o->created_at)->diffInMinutes($now));
                $stale = $ages->filter(fn (int $m) => $m >= UnconfirmedOrders::APPROVAL_AMBER_MINUTES)->count();
                if ($stale > 0) {
                    $add('approval', $ages->max() >= UnconfirmedOrders::APPROVAL_RED_MINUTES ? 'red' : 'amber', 'check',
                        __(':count orders are waiting for your approval.', ['count' => $stale]),
                        $stale, route('orders.index', ['filter' => 'approval']));
                }
            }

            // ---- 1C sync failed
            $failed = $orders()->where('sync_status', OrderSyncStatus::Failed->value)->count();
            $facts['failed_sync'] = $failed;
            if ($failed > 0) {
                $add('sync_failed', 'red', 'refresh', __(':count orders failed to sync to 1C.', ['count' => $failed]),
                    $failed, route('monitoring.unconfirmed-orders', ['type' => 'sync']));
            }

            // ---- Telegram did not reach the manager of a draft
            $notSent = $drafts->filter(fn (Order $o) => in_array($o->telegram_status, ['failed', 'no_recipients', 'no_token'], true))->count();
            $facts['telegram_failed'] = $notSent;
            if ($notSent > 0) {
                $add('telegram', 'amber', 'chat', __('The Telegram message did not reach the manager for :count order drafts.', ['count' => $notSent]),
                    $notSent, route('orders.index'));
            }
        }

        // ---- stock-outs and "not reported" (explicit zero rule)
        $stockUrl = $admin ? route('ordering.index') : route('dashboard').'#sec-stock-health';
        $warehouseId = Warehouse::shopId();
        $ruled = fn () => Stock::applyReported(Stock::applyCurrent(ShopAccess::restrictTo(DB::table('stocks'), 'stocks.shop_id', $ids)))
            ->join('item_order_rules', function ($join) {
                $join->on('item_order_rules.item_id', '=', 'stocks.item_id')->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
            })
            ->where('item_order_rules.min', '>', 0)
            ->when($warehouseId !== null, fn ($q) => $q->where('stocks.shop_id', '!=', $warehouseId));
        $ruledPairs = (int) $ruled()->count();
        $out = (int) $ruled()->where('stocks.qty', '<=', 0)->count();
        $facts['stockouts'] = $out;
        $facts['ruled_pairs'] = $ruledPairs;
        if ($out > 0) {
            $percent = $ruledPairs > 0 ? round($out / $ruledPairs * 100, 1) : 0.0;
            $add('stockouts', $percent > self::STOCKOUT_RED_PERCENT ? 'red' : 'amber', 'archive',
                __(':count item and shop pairs are out of stock (:percent% of the pairs with a rule).', ['count' => $out, 'percent' => $percent]),
                $out, $stockUrl);
        }

        $notReported = 0;
        if (Stock::explicitZero()) {
            $notReported = (int) Stock::applyNotReported(Stock::applyCurrent(ShopAccess::restrictTo(DB::table('stocks'), 'stocks.shop_id', $ids)))
                ->join('item_order_rules', function ($join) {
                    $join->on('item_order_rules.item_id', '=', 'stocks.item_id')->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
                })
                ->where('item_order_rules.min', '>', 0)
                ->when($warehouseId !== null, fn ($q) => $q->where('stocks.shop_id', '!=', $warehouseId))
                ->count();
        }
        $facts['not_reported'] = $notReported;
        if ($notReported > 0) {
            $add('not_reported', 'amber', 'inbox', __(':count item and shop pairs were not reported by the latest 1C sync.', ['count' => $notReported]),
                $notReported, $stockUrl);
        }

        // ---- sales and receipt rates (the Home board, shared cache)
        $board = app(ReceiptsMetrics::class)->cachedBoard($now, $ids, false);
        $t = $board['today'];
        $y = $board['yesterday'];
        $w = $board['week'];
        $a = Comparison::pct($t['sum'], $y['sum'], $y['count']);
        $b = Comparison::pct($t['sum'], $w['sum'], $w['count']);
        if (($s = $sev(Comparison::status($a, $b))) !== null) {
            $againstYesterday = $a !== null && ($b === null || $a <= $b);
            $pct = abs((float) ($againstYesterday ? $a : $b));
            $add('sales_drop', $s, 'chart',
                $againstYesterday
                    ? __('Sales are :pct% below yesterday at the same time.', ['pct' => number_format($pct, 1)])
                    : __('Sales are :pct% below the same weekday last week at the same time.', ['pct' => number_format($pct, 1)]),
                (int) round($pct), route('receipts.index'));
        }
        if (($s = $sev(Comparison::rateStatus((float) $t['refund_amount_percent'], (int) $t['all']))) !== null) {
            $add('refunds', $s, 'receipt', __('Refunds are :pct% of sales today.', ['pct' => number_format((float) $t['refund_amount_percent'], 1)]),
                (int) $t['refund_count'], route('receipts.index'));
        }
        if (($s = $sev(Comparison::rateStatus((float) $t['cancelled_percent'], (int) $t['all']))) !== null) {
            $add('cancelled', $s, 'receipt', __(':pct% of the receipts today were cancelled.', ['pct' => number_format((float) $t['cancelled_percent'], 1)]),
                (int) $t['cancelled_count'], route('receipts.index'));
        }

        if ($admin) {
            $this->warehouse($now, $add, $facts);

            // ---- security, data feed, background tasks
            $high = $this->securityHigh($now);
            if ($high > 0) {
                $add('security', 'red', 'shield', __(':count high security warnings in the last day.', ['count' => $high]), $high, route('security.warnings'));
            }

            $silent = (int) app(DataHealth::class)->cached(null, $now)['silent_count'];
            $facts['silent_shops'] = $silent;
            if ($silent > 0) {
                $add('silent_shops', 'red', 'pulse', __(':count shops sent far fewer receipts than usual.', ['count' => $silent]), $silent, route('data-health.index'));
            }

            $this->tasks($now, $add);
        }

        // Most severe first; the order of the sources above decides between equals. Capped.
        $rank = ['red' => 0, 'amber' => 1];
        $indexed = array_map(null, array_keys($rows), $rows);
        usort($indexed, fn ($p, $q) => [$rank[$p[1]['severity']], $p[0]] <=> [$rank[$q[1]['severity']], $q[0]]);
        $rows = array_slice(array_map(fn ($p) => $p[1], $indexed), 0, self::MAX);

        return ['rows' => $rows, 'facts' => $facts, 'checked_at' => $now->format('H:i')];
    }

    /** Main warehouse: stale sync, out-of-stock items, shops it cannot supply, pick-list coverage. */
    private function warehouse(Carbon $now, \Closure $add, array &$facts): void
    {
        $warehouse = Warehouse::shop();
        $facts['wh'] = ['configured' => $warehouse !== null, 'state' => null, 'out' => 0, 'a' => 0, 'coverage' => null, 'starved' => 0, 'short_items' => 0];
        if ($warehouse === null) {
            return;
        }

        $data = app(WarehouseOutOfStock::class)->data($now);
        $facts['wh']['state'] = $data['state'];
        $url = route('warehouse.out-of-stock');

        if ($data['state'] === 'not_synced') {
            $add('wh_not_synced', 'red', 'archive', __('The warehouse stock has not been synced since :date.', ['date' => $data['ref']]), 1, $url);
        } elseif ($data['state'] === 'ok' && ($data['kpi']['out'] ?? 0) > 0) {
            $facts['wh']['out'] = (int) $data['kpi']['out'];
            $facts['wh']['a'] = (int) $data['kpi']['a'];
            $add('wh_out', $data['kpi']['a'] > 0 ? 'red' : 'amber', 'archive',
                __(':count items are out of stock at the main warehouse.', ['count' => $data['kpi']['out']]), (int) $data['kpi']['out'], $url);
        }

        $plan = app(OrderRecommendations::class)->build(null, (int) $warehouse->id, $now);
        $need = array_sum(array_column($plan['pick'], 'need'));
        $facts['wh']['coverage'] = $need > 0 ? round(array_sum(array_column($plan['pick'], 'send')) / $need * 100, 1) : null;
        $facts['wh']['starved'] = (int) $plan['kpi']['starved'];
        $facts['wh']['short_items'] = (int) $plan['kpi']['short_items'];
        if ($plan['kpi']['starved'] > 0) {
            $add('wh_starved', 'red', 'truck', __(':count shop lines are out of stock and the warehouse cannot supply them.', ['count' => $plan['kpi']['starved']]),
                (int) $plan['kpi']['starved'], route('ordering.index'));
        }
        if ($plan['kpi']['short_items'] > 0) {
            $add('wh_short', 'amber', 'truck', __('The warehouse is short of :count items for the suggested orders.', ['count' => $plan['kpi']['short_items']]),
                (int) $plan['kpi']['short_items'], route('ordering.index'));
        }
    }

    /** HIGH security warnings since yesterday (the digest's window). The report is heavy: its own 10 minute cache. */
    private function securityHigh(Carbon $now): int
    {
        return (int) Cache::remember('attention.security.high.'.$now->toDateString(), 600, function () use ($now) {
            $shopIds = DB::table('shops')->pluck('id')->map(fn ($id) => (int) $id)->all();

            return app(SecurityWarnings::class)->build($now->copy()->subDay(), $now->copy(), $shopIds, $now)['kpi']['high'];
        });
    }

    /** Scheduler heartbeat, queue worker and failed jobs (the Monitoring > Tasks board, reduced to what is urgent). */
    private function tasks(Carbon $now, \Closure $add): void
    {
        $beat = TaskRuns::last(TaskRuns::HEARTBEAT);
        if ($beat === null || Carbon::parse($beat->finished_at)->lt($now->copy()->subMinutes(3))) {
            $add('scheduler', 'red', 'clock', $beat === null
                ? __('The scheduler has not reported yet: check the cron job.')
                : __('The scheduler has not reported for more than 3 minutes: check the cron job.'), 1, route('monitoring.tasks'));
        }

        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now->timestamp)->min('created_at');
            if ($oldest !== null && $now->timestamp - (int) $oldest > 120) {
                $add('queue', 'red', 'clock', __('The queue worker looks stopped: jobs have been waiting for more than 2 minutes.'), 1, route('monitoring.tasks'));
            }
        }

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        if ($failedJobs > 0) {
            $add('failed_jobs', 'amber', 'exclamation', __(':count background jobs failed.', ['count' => $failedJobs]), $failedJobs, route('monitoring.tasks'));
        }
    }
}
