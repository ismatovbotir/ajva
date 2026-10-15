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
 * Pushes one confirmed order to 1C (POST JSON, bearer token, Idempotency-Key = order number).
 * Any 2xx answer marks the order synced; anything else counts an attempt, stores the error and
 * retries with backoff; after the last try the order is marked failed (Retry sync re-queues it).
 * A synced order is never sent again. No ONEC_ORDERS_URL: the order stays queued (no crash).
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

        try {
            $request = Http::acceptJson()->asJson()
                ->timeout((int) config('services.onec.timeout', 30))
                ->withHeaders(['Idempotency-Key' => $order->number()]);
            if ((string) config('services.onec.token') !== '') {
                $request = $request->withToken((string) config('services.onec.token'));
            }

            $response = $request->post($url, $this->payload($order));

            if (! $response->successful()) {
                throw new RuntimeException('1C answered HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
            }
        } catch (Throwable $e) {
            $order->forceFill([
                'sync_attempts' => $order->sync_attempts + 1,
                'sync_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        $order->forceFill([
            'sync_status' => OrderSyncStatus::Synced,
            'synced_at' => now(),
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

    /** @return array<string, mixed> */
    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number(),
            'shop_id' => $order->shop_id,
            'warehouse_id' => $order->warehouse_id,
            'horizon_days' => $order->horizon_days,
            'created_at' => $order->created_at?->toIso8601String(),
            'lines' => $order->items->map(fn ($l) => ['item_id' => $l->item_id, 'qty' => (float) $l->qty])->values()->all(),
        ];
    }
}
