<?php

namespace App\Jobs;

use App\Enums\OrderSyncStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Pushes one confirmed order to 1C (POST JSON {wareCode, goods[{itemCode, itemQty}]}, HTTP Basic login, Idempotency-Key = order number).
 * Any 2xx answer marks the order synced; anything else counts an attempt, stores the error and
 * retries with backoff; after the last try the order is marked failed (Retry sync re-queues it).
 * A synced order is never sent again. No ADINES_URL: the order stays queued (no crash).
 * Needs a queue worker: php artisan queue:work --timeout=120 (timeout here stays below retry_after).
 */
class SyncOrderTo1C implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $orderId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(): void
    {
        $order = Order::query()->with('items')->find($this->orderId);
        if ($order === null || $order->sync_status === OrderSyncStatus::Synced) {
            return;
        }

        $url = (string) config('services.onec.orders_url');
        if ($url === '') {
            return;
        }

        $response = null;

        try {
            $request = Http::acceptJson()->asJson()
                ->timeout((int) config('services.onec.timeout', 30))
                ->withHeaders(['Idempotency-Key' => $order->number()]);
            if ((string) config('services.onec.user') !== '') {
                $request = $request->withBasicAuth(
                    (string) config('services.onec.user'),
                    (string) config('services.onec.password'),
                );
            }

            $response = $request->post($url, $this->payload($order));

            if (! $response->successful()) {
                throw new RuntimeException('1C answered HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
            }

            // The HTTP code is not enough: 1C answers {"status": "ok"|"error", "message": ...} with 200 either way.
            // Only status "ok" is a success; "message" is the 1C order number on success and the reason on error.
            $status = $response->json('status');
            if (! is_string($status) || strtolower(trim($status)) !== 'ok') {
                $reason = $response->json('message');
                $reason = is_scalar($reason) ? (string) $reason : (string) $response->body();
                throw new RuntimeException('1C answered status "'.(is_scalar($status) ? $status : 'missing').'": '.mb_substr($reason, 0, 300));
            }
        } catch (Throwable $e) {
            $order->forceFill([
                'sync_attempts' => $order->sync_attempts + 1,
                'sync_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        // On success 1C puts its own order number in "message" (e.g. 00-00002495).
        $message = $response->json('message');
        $onecNumber = is_scalar($message) ? mb_substr(trim((string) $message), 0, 60) : null;

        $order->forceFill([
            'sync_status' => OrderSyncStatus::Synced,
            'synced_at' => now(),
            'onec_number' => $onecNumber !== '' ? $onecNumber : null,
            'sync_attempts' => $order->sync_attempts + 1,
            'sync_error' => null,
        ])->save();
    }

    /** Called by the queue when the last try failed. */
    public function failed(Throwable $e): void
    {
        Order::query()->whereKey($this->orderId)
            ->where('sync_status', '!=', OrderSyncStatus::Synced->value)
            ->update(['sync_status' => OrderSyncStatus::Failed->value, 'sync_error' => mb_substr($e->getMessage(), 0, 1000)]);
    }

    /**
     * The 1C order document (sample .claude/sample/order_sample.json): the shop id and the lines,
     * nothing else. The order number still travels in the Idempotency-Key header.
     *
     * @return array{wareCode: int, goods: list<array{itemCode: int, itemQty: int|float}>}
     */
    private function payload(Order $order): array
    {
        return [
            'wareCode' => (int) $order->shop_id,
            'goods' => $order->items->map(function ($l) {
                $qty = (float) $l->qty;

                return [
                    'itemCode' => (int) $l->item_id,
                    'itemQty' => $qty == floor($qty) ? (int) $qty : $qty,   // whole pieces stay integers
                ];
            })->values()->all(),
        ];
    }
}
