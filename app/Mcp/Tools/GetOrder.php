<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\InvalidArguments;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\User;

class GetOrder extends BaseTool
{
    private const MAX_LINES = 200;

    private const MAX_EVENTS = 100;

    public function name(): string
    {
        return 'get_order';
    }

    public function description(): string
    {
        return 'One replenishment order by id or number (ORD-000123): header (status, 1C sync, Telegram delivery), every line with the quantity, what the forecast suggested (suggested_qty; a lower qty means the warehouse was short or the user edited it), whether it was added by hand and the forecast figures it was based on (estimated stock, min, speed per day, cover days), and the history of changes (who, through which channel: link, panel or system). Access links and tokens are never returned.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order' => ['type' => 'string', 'description' => 'Order number such as ORD-000123, or its numeric id.'],
            ],
            'required' => ['order'],
        ];
    }

    public function handle(array $arguments): array
    {
        $raw = trim((string) ($arguments['order'] ?? ''));
        if (! preg_match('/^(?:ORD-)?0*(\d+)$/i', $raw, $m)) {
            throw new InvalidArguments('order must be an order number like ORD-000123 or a numeric id.');
        }

        $order = Order::query()->with(['shop:id,name', 'warehouse:id,name'])->find((int) $m[1]);
        if ($order === null) {
            throw new InvalidArguments("Order {$raw} was not found.");
        }

        $lines = OrderItem::query()->where('order_id', $order->id)->with('item:id,name')->orderBy('id')->limit(self::MAX_LINES + 1)->get();
        $events = OrderEvent::query()->where('order_id', $order->id)->with('item:id,name')->orderBy('id')->limit(self::MAX_EVENTS + 1)->get();

        $people = User::query()
            ->whereIn('id', collect([$order->created_by, $order->confirmed_by])->merge($events->pluck('user_id'))->filter()->unique())
            ->pluck('name', 'id');

        return [
            'order' => [
                'id' => $order->id,
                'number' => $order->number(),
                'shop_id' => $order->shop_id,
                'shop' => $order->shop?->name,
                'warehouse' => $order->warehouse?->name,
                'status' => $order->status->value,
                'origin' => $order->origin,
                'horizon_days' => $order->horizon_days,
                'warnings' => $order->warnings,
                'created_at' => $this->stamp($order->created_at),
                'created_by' => $order->created_by === null ? null : ($people[$order->created_by] ?? null),
                'confirmed_at' => $this->stamp($order->confirmed_at),
                'confirmed_by' => $order->confirmed_by === null ? null : ($people[$order->confirmed_by] ?? null),
                'sync_status' => $order->sync_status->value,
                'sync_attempts' => (int) $order->sync_attempts,
                'synced_at' => $this->stamp($order->synced_at),
                'sync_error' => $this->scrub($order->sync_error),
                'telegram_status' => $order->telegram_status ?? 'not_tried',
                'telegram_sent_at' => $this->stamp($order->telegram_sent_at),
                'telegram_recipients_ok' => (int) $order->telegram_recipients_ok,
                'telegram_note' => $this->scrub($order->telegram_note),
            ],
            'lines_truncated' => $lines->count() > self::MAX_LINES,
            'lines' => $lines->take(self::MAX_LINES)->map(fn (OrderItem $l) => [
                'item_id' => $l->item_id,
                'item' => $l->item?->name,
                'qty' => round((float) $l->qty, 3),
                'suggested_qty' => $l->suggested_qty === null ? null : round((float) $l->suggested_qty, 3),
                'added_manually' => (bool) $l->added_manually,
                'estimated_stock' => $l->est === null ? null : round((float) $l->est, 3),
                'min' => $l->min === null ? null : round((float) $l->min, 3),
                'speed_per_day' => $l->speed === null ? null : round((float) $l->speed, 3),
                'cover_days' => $l->cover_days === null ? null : round((float) $l->cover_days, 1),
            ])->all(),
            'events_truncated' => $events->count() > self::MAX_EVENTS,
            'events' => $events->take(self::MAX_EVENTS)->map(fn (OrderEvent $e) => [
                'at' => $this->stamp($e->created_at),
                'action' => $e->action,
                'channel' => $e->channel,
                'user' => $e->user_id === null ? null : ($people[$e->user_id] ?? null),
                'item' => $e->item?->name,
                'old_qty' => $e->old_qty === null ? null : round((float) $e->old_qty, 3),
                'new_qty' => $e->new_qty === null ? null : round((float) $e->new_qty, 3),
                'note' => $e->note,
            ])->all(),
        ];
    }
}
