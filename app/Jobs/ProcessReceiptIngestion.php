<?php

namespace App\Jobs;

use App\Models\Pos;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessReceiptIngestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(protected array $data, protected int $posId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $pos = Pos::query()->findOrFail($this->posId);

            $active = $this->data['active'] ?? true;
            $sell = $this->data['sell'] ?? true;

            // Idempotent on (pos_id, number): a POS retrying a submission
            // after a network blip updates the existing receipt instead of
            // creating a duplicate.
            $receipt = Receipt::query()->updateOrCreate(
                ['pos_id' => $pos->id, 'number' => $this->data['number']],
                [
                    'shop_id' => $pos->shop_id,
                    'client' => $this->data['client'] ?? null,
                    'cashier' => $this->data['cashier'] ?? null,
                    'total' => $this->data['total'],
                    'discount' => $this->data['discount'] ?? 0,
                    'active' => $active,
                    'sell' => $sell,
                ]
            );

            // Re-ingesting the same receipt number replaces its line items
            // and payments rather than appending to them.
            $receipt->items()->delete();
            $receipt->payments()->delete();

            $now = now();

            $items = array_map(function (array $item) use ($receipt, $active, $sell, $now) {
                return [
                    'receipt_id' => $receipt->id,
                    'item_id' => $item['item_id'],
                    'active' => true,
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'total' => $item['total'],
                    'receipt_active' => $active,
                    'receipt_sell' => $sell,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $this->data['items']);

            ReceiptItem::query()->insert($items);

            $payments = array_map(function (array $payment) use ($receipt, $now) {
                return [
                    'receipt_id' => $receipt->id,
                    'payment' => $payment['payment'],
                    'value' => $payment['value'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $this->data['payments'] ?? []);

            if ($payments !== []) {
                ReceiptPayment::query()->insert($payments);
            }

            // Do NOT modify `stocks.qty` here — 1C is the sole authoritative
            // source for stock quantities via the separate /api/items sync.
            // Decrementing stock again here would risk double-adjustment
            // against 1C's own periodic sync.
        });
    }
}
