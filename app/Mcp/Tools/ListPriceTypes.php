<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use Illuminate\Support\Facades\DB;

class ListPriceTypes extends BaseTool
{
    public function name(): string
    {
        return 'list_price_types';
    }

    public function description(): string
    {
        return 'List the price types with their ids. Price id '.self::costId().' is the COST (purchase) price; every other price id is a SELL (retail/wholesale) price.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function handle(array $arguments): array
    {
        return [
            'cost_price_id' => self::costId(),
            'note' => 'Price id '.self::costId().' = cost price; all other ids = sell prices.',
            'price_types' => DB::table('prices')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'name' => $p->name,
                    'kind' => self::kind((int) $p->id),
                ])->all(),
        ];
    }

    public static function costId(): int
    {
        return (int) config('inventory.cost_price_id');
    }

    public static function kind(int $priceId): string
    {
        return $priceId === self::costId() ? 'cost' : 'sell';
    }
}
