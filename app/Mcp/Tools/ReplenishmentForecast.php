<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Services\OrderRecommendations;
use App\Support\Warehouse;

class ReplenishmentForecast extends BaseTool
{
    public function name(): string
    {
        return 'replenishment_forecast';
    }

    public function description(): string
    {
        return 'What each shop needs from the main warehouse, from the sales forecast behind the Ordering report: per shop the items to order (quantity to order for the horizon, stock now, estimated stock, quantity already on open orders, sales per day, days of cover, stock-out date, level out / critical / soon / low, forecast confidence) most urgent first, plus the consolidated warehouse pick list (need, available at the warehouse, what can be sent, shortage). horizon_days 1-7 applies one horizon to all shops; without it each shop uses its own order rule. The main warehouse itself is never replenished. Read-only: it does not create orders.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shop_ids' => self::SHOP_IDS_SCHEMA,
                'horizon_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7, 'description' => 'Days of sales to cover (1-7). Omit to use each shop\'s order rule.'],
                'top_n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'description' => 'Most urgent lines per shop (default 10).'],
                'pick_n' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 30, 'description' => 'Pick list items to return (default 15, 0 = none).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $shopIds = $this->shopIds($arguments);
        $horizon = $this->optionalInt($arguments, 'horizon_days', 1, 7);
        $top = $this->optionalInt($arguments, 'top_n', 1, 30) ?? 10;
        $pickN = $this->optionalInt($arguments, 'pick_n', 0, 30) ?? 15;

        $warehouse = Warehouse::shop();
        $plan = app(OrderRecommendations::class)->build($shopIds, $warehouse?->id, null, $horizon);

        $shops = array_map(fn (array $s) => [
            'shop_id' => $s['id'],
            'shop' => $s['name'],
            'horizon_days' => $s['horizon'],
            'lines' => $s['count'],
            'counts' => $s['counts'],
            'short_lines' => $s['short'],
            'items' => array_map(fn (array $r) => [
                'item_id' => $r['item_id'],
                'item' => $r['item'],
                'level' => $r['level'],
                'order_qty' => $r['need'],
                'can_send' => $r['send'],
                'short' => $r['short'],
                'stock' => $r['stock'],
                'estimated_stock' => $r['estimated'],
                'on_open_orders' => $r['in_transit'],
                'min' => $r['min'],
                'max' => $r['max'],
                'sales_per_day' => $r['speed'],
                'cover_days' => $r['cover'],
                'stockout_date' => $r['stockout_date'],
                'confidence' => $r['confidence'],
            ], array_slice($s['rows'], 0, $top)),
            'items_truncated' => $s['count'] > $top,
        ], array_values(array_filter($plan['shops'], fn (array $s) => $s['count'] > 0)));

        return [
            'generated_at' => $plan['generated_at'],
            'horizon_days' => $horizon ?? $plan['horizon'],
            'per_shop_rules' => $horizon === null,
            'warehouse' => ['configured' => $plan['warehouse_configured'], 'shop_id' => $warehouse?->id, 'name' => $warehouse?->name],
            'summary' => $plan['kpi'],
            'shops_without_needs' => count($plan['shops']) - count($shops),
            'shops' => $shops,
            'pick_list_items' => count($plan['pick']),
            'pick_list' => array_map(fn (array $i) => [
                'item_id' => $i['item_id'],
                'item' => $i['item'],
                'urgency' => $i['urgency'],
                'need' => $i['need'],
                'available' => $i['available'],
                'send' => $i['send'],
                'short' => $i['short'],
                'short_pct' => $i['short_pct'],
                'shops' => count($i['shops']),
                'starved' => $i['starved'],
            ], array_slice($plan['pick'], 0, $pickN)),
        ];
    }
}
