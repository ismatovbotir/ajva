<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Services\WarehouseOutOfStock as OutOfStockReport;

class WarehouseOutOfStock extends BaseTool
{
    public function name(): string
    {
        return 'warehouse_out_of_stock';
    }

    public function description(): string
    {
        return 'Items that are out of stock at the MAIN WAREHOUSE, found from the daily 1C stock data. 1C reports zero explicitly (explicit_zero_stock = true): only reason "zero" (quantity 0 or below in the latest warehouse sync) is out of stock; an item the latest sync did not report is "no_data" (not reported by 1C: unknown, NOT out of stock, only listed when reason = no_data, counted in not_reported_total). With explicit_zero_stock = false (legacy) reason "missing" means the latest sync no longer reports the item and partial_sync = true means the sync looks incomplete. Also days out, last known quantity, last in-stock date, chain sales per day (last 7 days, shops only), how many shops need the item and their total order quantity (from the Ordering forecast), quantity on open orders, ABC class and the shops\' min/max total. Returns a state: ok, not_synced (the warehouse was not synced with the other shops, nothing is judged), empty or unconfigured. Most important first, top 100. No cost or profit fields. Read-only.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'only_needed' => ['type' => 'boolean', 'description' => 'Only items the shops need (default true).'],
                'abc' => ['type' => 'string', 'enum' => ['A', 'B', 'C'], 'description' => 'Only this ABC class.'],
                'reason' => ['type' => 'string', 'enum' => ['missing', 'zero', 'no_data'], 'description' => 'Only this reason (no_data = not reported by 1C, not out of stock; missing = legacy mode only).'],
                'min_days_out' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Only items out at least this many days.'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $abc = $this->optionalEnum($arguments, 'abc', ['A', 'B', 'C']);
        $reason = $this->optionalEnum($arguments, 'reason', ['missing', 'zero', 'no_data']);
        $minDays = $this->optionalInt($arguments, 'min_days_out', 0, 3650) ?? 0;

        $service = app(OutOfStockReport::class);
        $data = $service->data();
        $rows = $service->apply($data['rows'], OutOfStockReport::filters([
            'needed' => ($arguments['only_needed'] ?? true) !== false,
            'abc' => $abc,
            'reason' => $reason,
            'min_days' => $minDays,
        ]));

        return [
            'state' => $data['state'],
            'warehouse' => $data['warehouse'],
            'stock_date' => $data['ref'],
            'latest_chain_stock_date' => $data['chain'],
            'explicit_zero_stock' => $data['explicit'],
            'not_reported_total' => $data['kpi']['not_reported'] ?? 0,
            'partial_sync' => $data['partial'],
            'sync_coverage' => $data['coverage'],
            'out_of_stock_total' => $data['kpi']['out'] ?? 0,
            'matching' => count($rows),
            'items' => array_map(fn (array $r) => [
                'item_id' => $r['item_id'],
                'item' => $r['name'],
                'sku' => $r['sku'],
                'group' => $r['group'],
                'abc' => $r['abc'],
                'reason' => $r['reason'],
                'last_qty' => $r['last_qty'],
                'last_in_stock_date' => $r['in_stock_date'],
                'days_out' => $r['days_out'],
                'chain_sales_per_day' => $r['speed'],
                'shops_needing' => $r['shops'],
                'need_qty' => $r['need'],
                'on_open_orders' => $r['open_orders'],
                'min' => $r['min'],
                'max' => $r['max'],
            ], array_slice($rows, 0, 100)),
            'items_truncated' => count($rows) > 100,
        ];
    }
}
