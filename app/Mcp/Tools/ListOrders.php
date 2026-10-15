<?php

namespace App\Mcp\Tools;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Mcp\BaseTool;
use App\Models\Order;
use App\Models\User;

class ListOrders extends BaseTool
{
    private const TELEGRAM = ['sent', 'failed', 'no_recipients', 'no_token', 'not_tried'];

    public function name(): string
    {
        return 'list_orders';
    }

    public function description(): string
    {
        return 'Replenishment orders (main warehouse to a shop), newest first: number (ORD-000123), shop, status (draft, confirmed, fulfilled, cancelled; older pending_approval / approved / rejected), origin (ordering_report or auto_schedule), horizon, line count, total quantity, created / confirmed times and people, the sync to 1C (not_queued, queued, synced, failed with the error) and the Telegram delivery of the draft link (sent, failed, no_recipients, no_token, not_tried). Filter by shops, status, creation date range, sync status and Telegram status. Use get_order for the lines and history of one order.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shop_ids' => self::SHOP_IDS_SCHEMA,
                'status' => ['type' => 'string', 'enum' => array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()), 'description' => 'Only orders in this status.'],
                'from' => ['type' => 'string', 'description' => 'Created on or after this day (YYYY-MM-DD).', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'to' => ['type' => 'string', 'description' => 'Created on or before this day (YYYY-MM-DD).', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'sync_status' => ['type' => 'string', 'enum' => array_map(fn (OrderSyncStatus $s) => $s->value, OrderSyncStatus::cases()), 'description' => 'Only orders with this 1C sync status (failed = needs attention).'],
                'telegram_status' => ['type' => 'string', 'enum' => self::TELEGRAM, 'description' => 'Only orders with this Telegram delivery result (not_tried = never sent).'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max orders (default 30).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $shopIds = $this->shopIds($arguments);
        $status = $this->optionalEnum($arguments, 'status', array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()));
        $sync = $this->optionalEnum($arguments, 'sync_status', array_map(fn (OrderSyncStatus $s) => $s->value, OrderSyncStatus::cases()));
        $telegram = $this->optionalEnum($arguments, 'telegram_status', self::TELEGRAM);
        $limit = $this->optionalInt($arguments, 'limit', 1, 100) ?? 30;
        $from = isset($arguments['from']) ? $this->dayRange(['date' => $arguments['from']])[0] : null;
        $to = isset($arguments['to']) ? $this->dayRange(['date' => $arguments['to']])[1] : null;

        $query = Order::query()
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($sync, fn ($q) => $q->where('sync_status', $sync))
            ->when($telegram === 'not_tried', fn ($q) => $q->whereNull('telegram_status'))
            ->when($telegram !== null && $telegram !== 'not_tried', fn ($q) => $q->where('telegram_status', $telegram))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $byStatus = (clone $query)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all();

        $orders = (clone $query)
            ->with('shop:id,name')
            ->withCount('items')
            ->withSum('items as qty_sum', 'qty')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $people = User::query()->whereIn('id', $orders->pluck('created_by')->merge($orders->pluck('confirmed_by'))->filter()->unique())->pluck('name', 'id');

        return [
            'matching' => array_sum($byStatus),
            'by_status' => $byStatus,
            'returned' => $orders->count(),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'number' => $o->number(),
                'shop_id' => $o->shop_id,
                'shop' => $o->shop?->name,
                'status' => $o->status->value,
                'origin' => $o->origin,
                'horizon_days' => $o->horizon_days,
                'lines' => (int) $o->items_count,
                'total_qty' => round((float) $o->qty_sum, 3),
                'created_at' => $this->stamp($o->created_at),
                'created_by' => $o->created_by === null ? null : ($people[$o->created_by] ?? null),
                'confirmed_at' => $this->stamp($o->confirmed_at),
                'confirmed_by' => $o->confirmed_by === null ? null : ($people[$o->confirmed_by] ?? null),
                'sync_status' => $o->sync_status->value,
                'sync_attempts' => (int) $o->sync_attempts,
                'synced_at' => $this->stamp($o->synced_at),
                'sync_error' => $this->scrub($o->sync_error, 160),
                'telegram_status' => $o->telegram_status ?? 'not_tried',
                'telegram_sent_at' => $this->stamp($o->telegram_sent_at),
            ])->all(),
        ];
    }
}
