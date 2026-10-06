<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use Illuminate\Support\Facades\DB;

class TopItems extends BaseTool
{
    public function name(): string
    {
        return 'top_items';
    }

    public function description(): string
    {
        return 'Best-selling items for one day ranked by quantity (successful sales only, storno lines excluded), with the sales total per item.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => self::DATE_SCHEMA,
                'shop_id' => self::SHOP_SCHEMA,
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many items to return (default 20).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        [$from, $to] = $this->dayRange($arguments);
        $shopId = $this->optionalInt($arguments, 'shop_id', 1);
        $limit = $this->optionalInt($arguments, 'limit', 1, 50) ?? 20;

        $items = DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->join('items', 'items.id', '=', 'receipt_items.item_id')
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->when($shopId, fn ($q) => $q->where('receipts.shop_id', $shopId))
            ->groupBy('receipt_items.item_id', 'items.name')
            ->orderByRaw('SUM(receipt_items.qty) DESC')
            ->limit($limit)
            ->selectRaw('receipt_items.item_id, items.name, SUM(receipt_items.qty) AS qty, SUM(receipt_items.total) AS total')
            ->get()
            ->values()
            ->map(fn ($r, $i) => [
                'rank' => $i + 1,
                'item_id' => (int) $r->item_id,
                'item' => $r->name,
                'qty' => round((float) $r->qty, 3),
                'total' => $this->money($r->total),
            ])->all();

        return ['date' => $from->toDateString(), 'shop_id' => $shopId, 'items' => $items];
    }
}
