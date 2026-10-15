<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Support\Warehouse;
use Illuminate\Support\Facades\DB;

class ListShops extends BaseTool
{
    public function name(): string
    {
        return 'list_shops';
    }

    public function description(): string
    {
        return 'List all shops with their ids and which one is the main warehouse (is_warehouse; set under Settings > Warehouse; it is not a selling shop and is never replenished). Use the ids to filter the other tools.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function handle(array $arguments): array
    {
        $warehouseId = Warehouse::shopId();

        return [
            'warehouse_shop_id' => $warehouseId,
            'shops' => DB::table('shops')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'is_warehouse' => (int) $s->id === $warehouseId])->all(),
        ];
    }
}
