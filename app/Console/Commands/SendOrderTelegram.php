<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLink;
use App\Services\OrderTelegram;
use App\Support\TelegramStatus;
use Illuminate\Console\Command;

class SendOrderTelegram extends Command
{
    protected $signature = 'orders:send-telegram
                            {order? : Order id or number (ORD-000123); sends that draft to everybody again}
                            {--all : Resend every draft, also the ones already sent}';

    protected $description = 'Send the personal order links of draft orders to the responsible people in Telegram (not sent yet or failed)';

    public function handle(OrderTelegram $telegram): int
    {
        $query = Order::query()->where('status', OrderStatus::Draft->value)->orderBy('id');

        $arg = $this->argument('order');
        if ($arg !== null) {
            $id = (int) ltrim(strtoupper((string) $arg), 'ORD-');
            $query->whereKey($id);
        } elseif (! $this->option('all')) {
            $query->where(fn ($q) => $q->whereNull('telegram_status')->orWhereIn('telegram_status', ['failed', 'no_token']));
        }

        $orders = $query->get();
        if ($orders->isEmpty()) {
            $this->line('No draft orders to send.');
            TelegramStatus::record('send', ['orders' => 0, 'sent' => 0, 'failed' => false]);

            return self::SUCCESS;
        }

        $failed = false;
        $sent = 0;
        foreach ($orders as $order) {
            // A previously failed order only goes to the people who still have no message; everything else is a full send.
            $onlyMissing = $arg === null && ! $this->option('all') && $order->telegram_status === 'failed';
            $only = null;
            if ($onlyMissing) {
                $delivered = OrderLink::query()->where('order_id', $order->id)->whereNotNull('telegram_sent_at')->pluck('user_id')->all();
                $only = OrderTelegram::recipients($order->shop_id)->pluck('id')->diff($delivered)->map(fn ($id) => (int) $id)->values()->all();
            }

            $result = $telegram->send($order, $only);
            $this->line(sprintf('%s: %s - %s', $order->number(), $result['status'], $result['note']));
            $failed = $failed || in_array($result['status'], ['failed', 'no_token'], true);   // no_token is stored on the order too
            $sent += $result['status'] === 'sent' ? 1 : 0;
        }

        TelegramStatus::record('send', ['orders' => $orders->count(), 'sent' => $sent, 'failed' => $failed]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
