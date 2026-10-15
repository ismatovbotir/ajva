<?php

namespace App\Mcp\Tools;

use App\Enums\UserRole;
use App\Mcp\BaseTool;
use App\Models\OrderRule;
use App\Support\Warehouse;
use Illuminate\Support\Facades\DB;

class OrderRulesReport extends BaseTool
{
    public function name(): string
    {
        return 'order_rules';
    }

    public function description(): string
    {
        return 'The order rules that apply to each shop (own setting, else the chain default, else the built-in default): whether the automatic daily draft is on, days of sales history for the forecast (14, 21 or 28), order horizon in days (1-7), run time, weekdays (0 = Sunday .. 6 = Saturday; empty = every day), the outcome of the last automatic run (date, status such as created, skipped_draft, skipped_empty, skipped_no_stock, waiting_stock, error, and a short note), how many items have a min/max rule, and how many responsible operators the shop has with and without a linked Telegram account (no chat ids or e-mails). The main warehouse is never listed.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['shop_ids' => self::SHOP_IDS_SCHEMA],
        ];
    }

    public function handle(array $arguments): array
    {
        $requested = $this->shopIds($arguments);
        $warehouseId = Warehouse::shopId();

        $shops = DB::table('shops')
            ->when($requested !== null, fn ($q) => $q->whereIn('id', $requested))
            ->when($warehouseId !== null, fn ($q) => $q->where('id', '!=', $warehouseId))
            ->orderBy('name')->pluck('name', 'id');
        $ids = $shops->keys()->map(fn ($id) => (int) $id)->all();

        $effective = OrderRule::effective($ids);
        $ruleItems = DB::table('item_order_rules')->whereIn('shop_id', $ids)->where('max', '>', 0)
            ->groupBy('shop_id')->selectRaw('shop_id, COUNT(*) AS c')->pluck('c', 'shop_id');

        // Responsible = active operators assigned to the shop; "with Telegram" = they have a linked chat.
        $people = DB::table('shop_user')
            ->join('users', 'users.id', '=', 'shop_user.user_id')
            ->whereIn('shop_user.shop_id', $ids)
            ->where('users.role', UserRole::Operator->value)
            ->where('users.is_active', true)
            ->groupBy('shop_user.shop_id')
            ->selectRaw("shop_user.shop_id AS shop_id, COUNT(*) AS total, SUM(CASE WHEN users.telegram_chat_id IS NOT NULL AND users.telegram_chat_id != '' THEN 1 ELSE 0 END) AS linked")
            ->get()->keyBy('shop_id');

        $rows = [];
        foreach ($ids as $id) {
            $e = $effective[$id];
            $p = $people->get($id);
            $rows[] = [
                'shop_id' => $id,
                'shop' => $shops[$id],
                'auto_enabled' => $e['enabled'],
                'own_settings' => $e['rule_id'] !== null,
                'history_days' => $e['history_days'],
                'horizon_days' => $e['horizon_days'],
                'run_time' => $e['run_time'],
                'weekdays' => $e['weekdays'],
                'last_run_date' => $e['last_run_date'],
                'last_run_status' => $e['last_run_status'],
                'last_run_note' => $this->scrub($e['last_run_note']),
                'items_with_rule' => (int) ($ruleItems[$id] ?? 0),
                'responsible_users' => (int) ($p->total ?? 0),
                'with_telegram' => (int) ($p->linked ?? 0),
                'without_telegram' => (int) ($p->total ?? 0) - (int) ($p->linked ?? 0),
            ];
        }

        return ['shops' => $rows];
    }
}
