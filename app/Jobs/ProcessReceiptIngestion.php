<?php

namespace App\Jobs;

use App\Models\Pos;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Support\ShopAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
            // POS terminals have no token — they're identified by the id in
            // the payload, and registered on first sight with a default name.
            $shopId = (int) $this->data['shop'];

            $pos = Pos::query()->firstOrCreate(
                ['id' => $this->posId],
                [
                    'name' => "kassa {$this->posId}",
                    'shop_id' => $shopId,
                ]
            );

            $status = $this->data['status'] ?? null;
            $active = $status === null ? true : $status === 'success';
            // No reliable signal yet for sale-vs-return from `type` — keep
            // defaulting to a sale until we see a payload that clarifies it.
            $sell = $this->data['sell'] ?? true;

            $grossTotal = $this->data['sum'] ?? null;
            $total = $this->data['total'];
            $discount = ($grossTotal ?? $total) - $total;

            $user = $this->data['user'] ?? [];

            $openedAt = $this->parseDateTime($this->data['openDate'], $this->data['openTime']);
            $closedAt = ! empty($this->data['closeDate'])
                ? $this->parseDateTime($this->data['closeDate'], $this->data['openTime'])
                : $openedAt;

            // Idempotent on (pos_id, number): a POS retrying a submission
            // after a network blip updates the existing receipt instead of
            // creating a duplicate.
            $receipt = Receipt::query()->updateOrCreate(
                ['pos_id' => $pos->id, 'number' => $this->data['number']],
                [
                    'shop_id' => $pos->shop_id ?? $shopId,
                    'client' => $this->data['client'] ?? null,
                    'cashier' => $user['name'] ?? null,
                    'total' => $total,
                    'discount' => $discount,
                    'active' => $active,
                    'sell' => $sell,
                    'barcode' => $this->data['barcode'] ?? null,
                    'card' => $this->data['card'] ?? null,
                    'qty_buys' => $this->data['qtyBuys'] ?? null,
                    'qty_positions' => $this->data['qtyPositions'] ?? null,
                    'session' => $this->data['session'] ?? null,
                    'type' => $this->data['type'] ?? null,
                    'status' => $status,
                    'gross_total' => $grossTotal,
                    'fiscal' => $this->data['fiscal'] ?? null,
                    'pos_user_id' => $user['id'] ?? null,
                    'pos_user_name' => $user['name'] ?? null,
                    'pos_user_text' => $user['text'] ?? null,
                    'aos' => $this->data['aos'] ?? null,
                ]
            );

            // The POS's openDate/openTime and closeDate are the real-world
            // receipt timestamps — set them explicitly via forceFill() (which
            // marks them dirty) so Eloquent's automatic timestamp handling,
            // which only touches columns that aren't already dirty, doesn't
            // overwrite them back to now().
            $receipt->forceFill([
                'created_at' => $openedAt,
                'updated_at' => $closedAt,
            ])->save();

            // Re-ingesting the same receipt number replaces its line items
            // and payments rather than appending to them.
            $receipt->items()->delete();
            $receipt->payments()->delete();

            $now = now();

            $items = array_map(function (array $position) use ($receipt, $active, $sell, $now) {
                $item = $position['item'];
                $qty = (float) $position['qty'];
                $totalSum = (float) $position['totalSum'];
                $sum = (float) ($position['sum'] ?? $totalSum);
                $storno = (bool) ($position['storno'] ?? false);

                return [
                    'receipt_id' => $receipt->id,
                    'item_id' => $item['id'],
                    'active' => ! $storno,
                    'qty' => $qty,
                    'price' => $qty > 0 ? $totalSum / $qty : $totalSum,
                    'discount' => $sum - $totalSum,
                    'total' => $totalSum,
                    'receipt_active' => $active,
                    'receipt_sell' => $sell,
                    'art' => $item['art'] ?? null,
                    'name' => $item['name'] ?? null,
                    'class_code' => $item['class_code'] ?? null,
                    'package_code' => $item['package_code'] ?? null,
                    'line_barcode' => $position['barcode'] ?? null,
                    'storno' => $storno,
                    'sum' => $sum,
                    'sum_r' => $position['sumR'] ?? 0,
                    'sum_wd' => $position['sumWD'] ?? null,
                    'sum_wt' => $position['sumWT'] ?? null,
                    'labels' => ! empty($position['labels']) ? json_encode($position['labels']) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $this->data['positions'] ?? []);

            if ($items !== []) {
                ReceiptItem::query()->insert($items);
            }

            $payments = array_map(function (array $payment) use ($receipt, $now) {
                return [
                    'receipt_id' => $receipt->id,
                    'payment' => $payment['name'],
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

        // Sales caches (board, monitor, receipts analytics) are keyed by shop
        // scope as well as day, so bump the shared version instead of
        // forgetting keys: every scope variant is invalidated at once.
        ShopAccess::bumpSalesVersion();
    }

    protected function parseDateTime(string $date, string $time): Carbon
    {
        return Carbon::createFromFormat('d.m.y H:i:s', "{$date} {$time}");
    }
}
