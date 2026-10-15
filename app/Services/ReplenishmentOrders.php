<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Support\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Prepare order" (admin, Ordering page): saves a shop's suggested list as an editable DRAFT in `orders` +
 * `order_items` (qty = send, warehouse-limited, = need without a warehouse; suggested_qty = the needed quantity;
 * est / min / speed / cover_days = the forecast figures). One draft per shop: pressing it again adds only the
 * items that are NOT in the draft yet and never touches an existing line (edited quantities, manual lines and
 * the suggestions already shown stay). The shop's person then edits and confirms it under Orders
 * (OrderSync::confirm) - or through the secret link of the draft (/o/{token}).
 *
 * prepareAuto() is the same for the daily automatic run (orders:auto-generate, origin auto_schedule, created_by
 * null, App\Services\AutoOrders): it never touches an existing draft, leaves out lines without a usable demand
 * history (confidence none) and pairs with stale stock, caps a line at 3 x the item's max rule and the draft at
 * 150 lines, and never creates an empty draft.
 */
class ReplenishmentOrders
{
    public const AUTO_MAX_LINES = 150;

    public const AUTO_QTY_FACTOR = 3;

    /** Days out of stock in the history window from which the measured demand counts as understated. */
    public const UNDERSTATED_DAYS = 7;

    /**
     * @param  array<string, mixed>  $plan  OrderRecommendations::plan() result
     * @return array{created: array<int, array<string, mixed>>, updated: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    public function prepare(array $plan, int $shopId, User $user): array
    {
        $out = ['created' => [], 'updated' => [], 'skipped' => []];
        $shop = collect($plan['shops'])->firstWhere('id', $shopId);
        if ($shop === null) {
            return $out;
        }
        $entry = ['shop_id' => $shop['id'], 'shop' => $shop['name']];

        if (! Gate::forUser($user)->allows('generate', Order::class)) {
            $out['skipped'][] = $entry + ['reason' => 'forbidden'];

            return $out;
        }

        $horizon = (int) ($shop['horizon'] ?? $plan['horizon']);
        $lines = [];
        foreach ($shop['rows'] as $row) {
            $qty = $plan['warehouse_configured'] ? $row['send'] : $row['need'];
            if ($qty > 0) {
                $lines[$row['item_id']] = $this->line($row, $qty, $horizon);
            }
        }
        if ($lines === []) {
            $out['skipped'][] = $entry + ['reason' => 'empty'];

            return $out;
        }

        $draft = Order::query()->where('shop_id', $shop['id'])->where('status', OrderStatus::Draft->value)->first();
        if ($draft === null) {
            $draft = $this->createDraft($shop['id'], $user->id, Order::ORIGIN_ORDERING_REPORT, $horizon, $lines, null);
            $out['created'][] = $entry + ['order_id' => $draft->id, 'lines' => count($lines)];

            return $out;
        }

        $have = $draft->items()->pluck('item_id')->all();
        $new = array_values(array_diff_key($lines, array_flip($have)));
        if ($new !== []) {
            $draft->items()->createMany($new);
            OrderEvent::record($draft, $user->id, OrderEvent::CHANNEL_PANEL, OrderEvent::LINES_ADDED, null, null, null, (string) count($new));
        }
        $out['updated'][] = $entry + ['order_id' => $draft->id, 'lines' => count($new)];

        return $out;
    }

    /**
     * The automatic daily draft of one shop (system user: no policy check).
     *
     * @param  array<string, mixed>  $plan  OrderRecommendations::plan() result (warehouse-limited send quantities)
     * @return array{status: string, order: ?Order, lines: int, pieces: float, stale: int, none: int, capped: int, truncated: int}
     *                                                                                                                             status: created | empty | draft_exists
     */
    public function prepareAuto(array $plan, int $shopId): array
    {
        $out = ['status' => 'empty', 'order' => null, 'lines' => 0, 'pieces' => 0.0, 'stale' => 0, 'none' => 0, 'capped' => 0, 'truncated' => 0];
        $shop = collect($plan['shops'])->firstWhere('id', $shopId);
        if ($shop === null) {
            return $out;
        }

        $horizon = (int) ($shop['horizon'] ?? $plan['horizon']);
        $lines = [];
        foreach ($shop['rows'] as $row) {
            // No usable demand history (rules only) or a stock figure that is too old: never ordered automatically.
            if ($row['confidence'] === 'none') {
                $out['none']++;

                continue;
            }
            if ($row['stale']) {
                $out['stale']++;

                continue;
            }

            $qty = $plan['warehouse_configured'] ? $row['send'] : $row['need'];
            if ($qty <= 0) {
                continue;
            }
            if ($row['max'] > 0 && $qty > self::AUTO_QTY_FACTOR * $row['max']) {
                $qty = round(self::AUTO_QTY_FACTOR * $row['max'], 3);
                $out['capped']++;
            }
            $lines[$row['item_id']] = $this->line($row, $qty, $horizon);
        }

        // Rows arrive most urgent first, so the cut drops the least urgent lines.
        if (count($lines) > self::AUTO_MAX_LINES) {
            $out['truncated'] = count($lines) - self::AUTO_MAX_LINES;
            $lines = array_slice($lines, 0, self::AUTO_MAX_LINES, true);
        }
        if ($lines === []) {
            return $out;
        }

        $warnings = array_filter(['stale' => $out['stale'], 'none' => $out['none'], 'capped' => $out['capped'], 'truncated' => $out['truncated']]);
        $warnings = implode(',', array_map(fn ($k, $v) => $k.'='.$v, array_keys($warnings), $warnings));

        $order = DB::transaction(function () use ($shopId, $horizon, $lines, $warnings) {
            // Never touch a draft somebody may be editing.
            if (Order::query()->where('shop_id', $shopId)->where('status', OrderStatus::Draft->value)->lockForUpdate()->exists()) {
                return null;
            }

            return $this->createDraft($shopId, null, Order::ORIGIN_AUTO_SCHEDULE, $horizon, $lines, $warnings === '' ? null : $warnings);
        });
        if ($order === null) {
            $out['status'] = 'draft_exists';

            return $out;
        }

        $out['status'] = 'created';
        $out['order'] = $order;
        $out['lines'] = count($lines);
        $out['pieces'] = (float) array_sum(array_column($lines, 'qty'));

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createDraft(int $shopId, ?int $createdBy, string $origin, int $horizon, array $lines, ?string $warnings): Order
    {
        return DB::transaction(function () use ($shopId, $createdBy, $origin, $horizon, $lines, $warnings) {
            $draft = Order::query()->create([
                'shop_id' => $shopId,
                'warehouse_id' => Warehouse::shopId(),
                'created_by' => $createdBy,
                'status' => OrderStatus::Draft,
                'origin' => $origin,
                'horizon_days' => $horizon,
                'warnings' => $warnings,
            ]);
            $draft->items()->createMany(array_values($lines));
            OrderEvent::record($draft, $createdBy, $createdBy === null ? OrderEvent::CHANNEL_SYSTEM : OrderEvent::CHANNEL_PANEL, OrderEvent::CREATED, null, null, null, (string) count($lines));

            return $draft;
        });
    }

    /**
     * One order line from a plan row. Flags (for the Telegram message): out = no stock left, short = the warehouse
     * sends less than needed, understated = out of stock for a week or more in the history (demand is underestimated),
     * low = few days of sales behind the speed.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function line(array $row, float $qty, int $horizon): array
    {
        $flags = array_keys(array_filter([
            'out' => $row['estimated'] <= 0,
            'short' => ($row['short'] ?? 0) > 0 || $qty + 1e-9 < $row['need'],
            'understated' => ($row['censored_days'] ?? 0) >= self::UNDERSTATED_DAYS,
            'low' => ($row['confidence'] ?? null) === 'low',
        ]));

        return [
            'item_id' => $row['item_id'],
            'qty' => $qty,
            'suggested_qty' => $row['need'],
            'horizon_days' => $horizon,
            'est' => $row['estimated'],
            'min' => $row['min'],
            'speed' => $row['speed'],
            'cover_days' => $row['cover'],
            'flags' => $flags === [] ? null : implode(',', $flags),
        ];
    }
}
