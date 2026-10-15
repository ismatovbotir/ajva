<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Jobs\SendOrderTelegram;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\OrderRule;
use App\Models\Shop;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily automatic DRAFT orders (command orders:auto-generate, every 5 minutes). Per enabled shop, once a day, when
 * its run_time has passed on an allowed weekday (rules: App\Models\OrderRule, shop value or chain default):
 *
 *  1. today's stock snapshot (stocks.stock_date = today) must exist. Missing: status waiting_stock and the next tick
 *     tries again; 4 hours after run_time the day is given up (skipped_no_stock).
 *  2. the run is claimed atomically (OrderRule::claim: last_run_date empty or before today), so two overlapping
 *     processes can never both create a draft.
 *  3. a shop that already has a DRAFT is skipped (skipped_draft): a draft being edited is never touched.
 *  4. all claimed shops are forecast and planned together (ForecastService with each shop's history window,
 *     OrderRecommendations::plan with each shop's horizon): the warehouse stock is split over the shops, most urgent
 *     first, and the short quantity is recorded on the line (suggested_qty - qty).
 *  5. ReplenishmentOrders::prepareAuto writes the draft (origin auto_schedule, created_by null); nothing to order =
 *     skipped_empty, no empty draft. Open confirmed orders already count as in-transit (no double count).
 *  6. Telegram: the link goes to the Telegram chats of the shop's responsible users (operators of shop_user); a failure never fails the order (note + one queued retry job).
 *
 * The outcome per shop is stored in order_rules.last_run_status / last_run_note and logged.
 */
class AutoOrders
{
    /** Hours after run_time after which a missing stock snapshot ends the day's attempt. */
    public const STOCK_WAIT_HOURS = 4;

    public function __construct(
        private readonly ForecastService $forecast,
        private readonly OrderRecommendations $recommendations,
        private readonly OrderTelegram $telegram,
    ) {}

    /**
     * @return array<int, array{shop_id: int, shop: string, status: string, note: ?string}> shops that were handled in this tick
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $today = $now->toDateString();
        $warehouseId = Warehouse::shopId();

        // Only shops that have item order rules (min/max) can ever get a line.
        $shops = Shop::query()
            ->when($warehouseId !== null, fn ($q) => $q->whereKeyNot($warehouseId))
            ->whereIn('id', ItemOrderRule::query()->where('max', '>', 0)->select('shop_id'))
            ->orderBy('id')->pluck('name', 'id')->all();
        if ($shops === []) {
            return [];
        }

        $report = [];
        $claimed = [];
        foreach (OrderRule::effective(array_keys($shops)) as $shopId => $rule) {
            if (! $this->due($rule, $now)) {
                continue;
            }

            $runAt = Carbon::parse($today.' '.$rule['run_time']);
            $row = OrderRule::ownRow($shopId);

            if (! $this->hasTodaysStock($shopId, $today)) {
                if ($now->lt($runAt->copy()->addHours(self::STOCK_WAIT_HOURS))) {
                    OrderRule::recordRun($row->id, 'waiting_stock', __('Waiting for today\'s stock snapshot from 1C; the next run tries again.'));
                    $report[$shopId] = $this->entry($shopId, $shops, 'waiting_stock', null);
                } elseif (OrderRule::claim($row->id, $today)) {
                    $this->finish($row->id, $report, $shopId, $shops, 'skipped_no_stock', __('Skipped: no stock snapshot for today arrived within :hours hours of the run time.', ['hours' => self::STOCK_WAIT_HOURS]));
                }

                continue;
            }

            if (OrderRule::claim($row->id, $today)) {
                $claimed[$shopId] = $row->id;
            }
        }

        // A draft being edited (or waiting for its manager) is never touched.
        $open = Order::query()->whereIn('shop_id', array_keys($claimed))->where('status', OrderStatus::Draft->value)->pluck('id', 'shop_id')->all();
        foreach ($open as $shopId => $orderId) {
            $this->finish($claimed[$shopId], $report, $shopId, $shops, 'skipped_draft', __('Skipped: draft :number already exists.', ['number' => 'ORD-'.str_pad((string) $orderId, 6, '0', STR_PAD_LEFT)]));
            unset($claimed[$shopId]);
        }
        if ($claimed === []) {
            return array_values($report);
        }

        try {
            $forecast = $this->forecast->build(array_keys($claimed), $warehouseId === null ? [] : [$warehouseId], $now->copy());
            $plan = $this->recommendations->plan($forecast, null, $warehouseId);
        } catch (Throwable $e) {
            report($e);
            foreach ($claimed as $shopId => $ruleId) {
                $this->finish($ruleId, $report, $shopId, $shops, 'error', __('Error: :message', ['message' => mb_substr($e->getMessage(), 0, 200)]));
            }

            return array_values($report);
        }

        // plan() lists the most urgent shops first.
        $order = array_column($plan['shops'], 'id');
        foreach (array_diff(array_keys($claimed), $order) as $rest) {
            $order[] = $rest;
        }

        foreach ($order as $shopId) {
            if (! isset($claimed[$shopId])) {
                continue;
            }
            try {
                [$status, $note] = $this->draft($plan, $shopId);
            } catch (Throwable $e) {
                report($e);
                [$status, $note] = ['error', __('Error: :message', ['message' => mb_substr($e->getMessage(), 0, 200)])];
            }
            $this->finish($claimed[$shopId], $report, $shopId, $shops, $status, $note);
        }

        return array_values($report);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{0: string, 1: string}
     */
    private function draft(array $plan, int $shopId): array
    {
        $result = app(ReplenishmentOrders::class)->prepareAuto($plan, $shopId);

        if ($result['status'] === 'draft_exists') {
            return ['skipped_draft', __('Skipped: a draft already exists.')];
        }
        if ($result['status'] !== 'created') {
            $left = $result['stale'] + $result['none'];

            return ['skipped_empty', $left > 0
                ? __('Nothing to order. :count items were left out (stale stock or too little sales history).', ['count' => $left])
                : __('Nothing to order.')];
        }

        /** @var Order $order */
        $order = $result['order'];
        $parts = [__(':lines lines, total quantity :qty.', ['lines' => $result['lines'], 'qty' => rtrim(rtrim(number_format($result['pieces'], 3, '.', ''), '0'), '.')])];
        $result['stale'] > 0 && $parts[] = __(':count items left out: stale stock.', ['count' => $result['stale']]);
        $result['none'] > 0 && $parts[] = __(':count items left out: too little sales history.', ['count' => $result['none']]);
        $result['capped'] > 0 && $parts[] = __(':count quantities capped at 3 x max.', ['count' => $result['capped']]);
        $result['truncated'] > 0 && $parts[] = __(':count lines cut at the limit of :limit.', ['count' => $result['truncated'], 'limit' => ReplenishmentOrders::AUTO_MAX_LINES]);
        $parts[] = $this->notify($order);

        return ['created', implode(' ', $parts)];
    }

    /** Telegram never fails the order: errors become a note and one queued retry. */
    private function notify(Order $order): string
    {
        try {
            $result = $this->telegram->send($order);
        } catch (Throwable $e) {
            report($e);
            $result = ['status' => 'failed', 'note' => __('Telegram: sending failed.'), 'failed_user_ids' => []];
        }

        if ($result['status'] === 'failed') {
            try {
                SendOrderTelegram::dispatch($order->id, $result['failed_user_ids'] ?? [])->delay(now()->addMinutes(2));
                $result['note'] .= ' '.__('A retry is queued.');
            } catch (Throwable $e) {
                Log::warning('Telegram retry could not be queued', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $result['note'];
    }

    /** @param  array<string, mixed>  $rule */
    private function due(array $rule, Carbon $now): bool
    {
        if (! $rule['enabled']) {
            return false;
        }
        if ($rule['weekdays'] !== [] && ! in_array($now->dayOfWeek, $rule['weekdays'], true)) {
            return false;
        }
        if ($rule['last_run_date'] === $now->toDateString()) {
            return false;
        }

        return $now->gte(Carbon::parse($now->toDateString().' '.$rule['run_time']));
    }

    private function hasTodaysStock(int $shopId, string $today): bool
    {
        return DB::table('stocks')->where('shop_id', $shopId)->where('stock_date', '>=', $today)->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $report
     * @param  array<int, string>  $shops
     */
    private function finish(int $ruleId, array &$report, int $shopId, array $shops, string $status, string $note): void
    {
        OrderRule::recordRun($ruleId, $status, $note);
        Log::info('Auto order run', ['shop' => $shopId, 'status' => $status, 'note' => $note]);
        $report[$shopId] = $this->entry($shopId, $shops, $status, $note);
    }

    /** @param  array<int, string>  $shops */
    private function entry(int $shopId, array $shops, string $status, ?string $note): array
    {
        return ['shop_id' => $shopId, 'shop' => (string) ($shops[$shopId] ?? '#'.$shopId), 'status' => $status, 'note' => $note];
    }
}
