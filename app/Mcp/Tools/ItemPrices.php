<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\InvalidArguments;
use Illuminate\Support\Facades\DB;

class ItemPrices extends BaseTool
{
    public function name(): string
    {
        return 'item_prices';
    }

    public function description(): string
    {
        return 'Prices per item. Each price has a kind: price id '.ListPriceTypes::costId().' is the COST price (kind "cost"), every other id is a SELL price (kind "sell"). margin_percent is (sell - cost) / sell for sell prices when the item has a cost.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer', 'description' => 'Only this item id.'],
                'search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Filter by part of the item name.'],
                'price_id' => ['type' => 'integer', 'description' => 'Only this price type id (see list_price_types).'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max items (default 50).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $itemId = $this->optionalInt($arguments, 'item_id', 1);
        $priceId = $this->optionalInt($arguments, 'price_id', 1);
        $limit = $this->optionalInt($arguments, 'limit', 1, 100) ?? 50;
        $search = $arguments['search'] ?? null;
        if ($search !== null && (! is_string($search) || mb_strlen($search) > 100)) {
            throw new InvalidArguments('search must be a string of at most 100 characters.');
        }

        $items = DB::table('items')
            ->when($itemId, fn ($q) => $q->where('items.id', $itemId))
            ->when($search, fn ($q) => $q->where('items.name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('item_prices')
                ->whereColumn('item_prices.item_id', 'items.id')
                ->when($priceId, fn ($q) => $q->where('item_prices.price_id', $priceId)))
            ->orderBy('items.name')
            ->limit($limit)
            ->get(['items.id', 'items.name']);

        $prices = DB::table('item_prices')
            ->join('prices', 'prices.id', '=', 'item_prices.price_id')
            ->whereIn('item_prices.item_id', $items->pluck('id'))
            ->orderBy('prices.id')
            ->get(['item_prices.item_id', 'prices.id as price_id', 'prices.name as price_name', 'item_prices.value'])
            ->groupBy('item_id');

        return [
            'cost_price_id' => ListPriceTypes::costId(),
            'items' => $items->map(function ($item) use ($prices, $priceId) {
                $rows = $prices[$item->id] ?? collect();
                $cost = (float) ($rows->firstWhere('price_id', ListPriceTypes::costId())->value ?? 0);

                return [
                    'item_id' => (int) $item->id,
                    'item' => $item->name,
                    'prices' => $rows
                        ->when($priceId, fn ($c) => $c->where('price_id', $priceId))
                        ->map(function ($p) use ($cost) {
                            $kind = ListPriceTypes::kind((int) $p->price_id);
                            $value = (float) $p->value;

                            return [
                                'price_id' => (int) $p->price_id,
                                'price_name' => $p->price_name,
                                'kind' => $kind,
                                'value' => $this->money($value),
                                'margin_percent' => $kind === 'sell' && $cost > 0 && $value > 0
                                    ? round(($value - $cost) / $value * 100, 1) : null,
                            ];
                        })->values()->all(),
                ];
            })->all(),
        ];
    }
}
