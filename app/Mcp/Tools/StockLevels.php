<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\InvalidArguments;
use Illuminate\Support\Facades\DB;

class StockLevels extends BaseTool
{
    public function name(): string
    {
        return 'stock_levels';
    }

    public function description(): string
    {
        return 'Current stock per shop and item (as last synced from 1C) with the min/max order rule and a below_min flag.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shop_id' => self::SHOP_SCHEMA,
                'search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Filter by part of the item name.'],
                'only_below_min' => ['type' => 'boolean', 'description' => 'Only rows where stock is under the min rule.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max rows (default 50).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $shopId = $this->optionalInt($arguments, 'shop_id', 1);
        $limit = $this->optionalInt($arguments, 'limit', 1, 100) ?? 50;
        $search = $arguments['search'] ?? null;
        if ($search !== null && (! is_string($search) || mb_strlen($search) > 100)) {
            throw new InvalidArguments('search must be a string of at most 100 characters.');
        }

        $rows = DB::table('stocks')
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->join('shops', 'shops.id', '=', 'stocks.shop_id')
            ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
            ->leftJoin('item_order_rules', function ($join) {
                $join->on('item_order_rules.item_id', '=', 'stocks.item_id')
                    ->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
            })
            ->when($shopId, fn ($q) => $q->where('stocks.shop_id', $shopId))
            ->when($search, fn ($q) => $q->where('items.name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when(! empty($arguments['only_below_min']), fn ($q) => $q->whereColumn('stocks.qty', '<', 'item_order_rules.min'))
            ->orderBy('shops.name')
            ->orderBy('items.name')
            ->limit($limit)
            ->get([
                'stocks.shop_id', 'shops.name as shop', 'stocks.item_id', 'items.name as item',
                'groups.name as group', 'stocks.qty', 'item_order_rules.min', 'item_order_rules.max',
            ]);

        return [
            'rows' => $rows->map(fn ($r) => [
                'shop_id' => (int) $r->shop_id,
                'shop' => $r->shop,
                'item_id' => (int) $r->item_id,
                'item' => $r->item,
                'group' => $r->group,
                'qty' => round((float) $r->qty, 3),
                'min' => $r->min === null ? null : round((float) $r->min, 3),
                'max' => $r->max === null ? null : round((float) $r->max, 3),
                'below_min' => $r->min !== null && (float) $r->qty < (float) $r->min,
            ])->all(),
        ];
    }
}
