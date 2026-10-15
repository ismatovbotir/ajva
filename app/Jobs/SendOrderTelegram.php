<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderRule;
use App\Services\OrderTelegram;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Retry of a failed Telegram message for a draft order (3 tries with backoff). A missing token / recipient or an order
 * that is no longer a draft ends quietly; only a real send failure is retried. The outcome is appended to the shop's
 * last run note.
 */
class SendOrderTelegram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param  array<int, int>  $userIds  only these users (the ones that failed first); empty = every recipient */
    public function __construct(public int $orderId, public array $userIds = []) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(OrderTelegram $telegram): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order === null) {
            return;
        }

        $result = $telegram->send($order, $this->userIds === [] ? null : $this->userIds);
        if ($result['status'] === 'failed') {
            throw new RuntimeException($result['note']);
        }

        OrderRule::appendNote($order->shop_id, $result['note']);
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Order Telegram message failed after retries', ['order' => $this->orderId, 'error' => $e->getMessage()]);

        $order = Order::query()->find($this->orderId);
        if ($order !== null) {
            app(OrderTelegram::class)->store($order, ['status' => 'failed', 'note' => __('Telegram: still failing after retries.'), 'failed_user_ids' => []], 0);
            OrderRule::appendNote($order->shop_id, __('Telegram: still failing after retries.'));
        }
    }
}
