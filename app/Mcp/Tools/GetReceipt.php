<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\InvalidArguments;
use Illuminate\Support\Facades\DB;

class GetReceipt extends BaseTool
{
    public function name(): string
    {
        return 'get_receipt';
    }

    public function description(): string
    {
        return 'One receipt with its line items and payments, by receipt id.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['receipt_id' => ['type' => 'integer', 'description' => 'The receipt id (the /receipts/{id} route).']],
            'required' => ['receipt_id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $id = $this->optionalInt($arguments, 'receipt_id', 1);
        if ($id === null) {
            throw new InvalidArguments('receipt_id is required.');
        }

        $receipt = DB::table('receipts')
            ->join('shops', 'shops.id', '=', 'receipts.shop_id')
            ->join('pos', 'pos.id', '=', 'receipts.pos_id')
            ->where('receipts.id', $id)
            ->first(['receipts.*', 'shops.name as shop_name', 'pos.name as pos_name']);

        if (! $receipt) {
            throw new InvalidArguments("Receipt {$id} not found.");
        }

        $items = DB::table('receipt_items')
            ->join('items', 'items.id', '=', 'receipt_items.item_id')
            ->where('receipt_items.receipt_id', $id)
            ->orderBy('receipt_items.id')
            ->get(['receipt_items.item_id', 'items.name', 'receipt_items.qty', 'receipt_items.price', 'receipt_items.discount', 'receipt_items.total', 'receipt_items.storno']);

        $payments = DB::table('receipt_payments')->where('receipt_id', $id)->get(['payment', 'value']);

        return [
            'id' => (int) $receipt->id,
            'number' => $receipt->number,
            'time' => $receipt->created_at,
            'shop' => ['id' => (int) $receipt->shop_id, 'name' => $receipt->shop_name],
            'pos' => $receipt->pos_name,
            'cashier' => $receipt->cashier,
            'status' => $receipt->status,
            'active' => (bool) $receipt->active,
            'type' => $receipt->sell ? 'sell' : 'refund',
            'total' => $this->money($receipt->total),
            'discount' => $this->money($receipt->discount),
            'items' => $items->map(fn ($i) => [
                'item_id' => (int) $i->item_id,
                'item' => $i->name,
                'qty' => round((float) $i->qty, 3),
                'price' => $this->money($i->price),
                'discount' => $this->money($i->discount),
                'total' => $this->money($i->total),
                'storno' => (bool) $i->storno,
            ])->all(),
            'payments' => $payments->map(fn ($p) => ['payment' => $p->payment, 'value' => $this->money($p->value)])->all(),
        ];
    }
}
