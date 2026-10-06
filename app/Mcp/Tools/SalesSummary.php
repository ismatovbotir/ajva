<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use Illuminate\Support\Facades\DB;

class SalesSummary extends BaseTool
{
    public function name(): string
    {
        return 'sales_summary';
    }

    public function description(): string
    {
        return 'Receipt counts and totals per shop for one day: successful sales, successful refunds and cancelled receipts, plus net total (sales minus refunds).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['date' => self::DATE_SCHEMA, 'shop_id' => self::SHOP_SCHEMA],
        ];
    }

    public function handle(array $arguments): array
    {
        [$from, $to] = $this->dayRange($arguments);
        $shopId = $this->optionalInt($arguments, 'shop_id', 1);

        $rows = DB::table('receipts')
            ->whereBetween('created_at', [$from, $to])
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->groupBy('shop_id')
            ->selectRaw('shop_id,
                SUM(CASE WHEN active = 1 AND sell = 1 THEN 1 ELSE 0 END) AS sell_count,
                SUM(CASE WHEN active = 1 AND sell = 1 THEN total ELSE 0 END) AS sell_total,
                SUM(CASE WHEN active = 1 AND sell = 0 THEN 1 ELSE 0 END) AS refund_count,
                SUM(CASE WHEN active = 1 AND sell = 0 THEN total ELSE 0 END) AS refund_total,
                SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) AS cancelled_count')
            ->get();

        $names = DB::table('shops')->pluck('name', 'id');
        $totals = ['sell_count' => 0, 'sell_total' => 0.0, 'refund_count' => 0, 'refund_total' => 0.0, 'cancelled_count' => 0];

        $shops = $rows->map(function ($r) use ($names, &$totals) {
            $row = [
                'shop_id' => (int) $r->shop_id,
                'shop' => $names[$r->shop_id] ?? '#'.$r->shop_id,
                'sell_count' => (int) $r->sell_count,
                'sell_total' => $this->money($r->sell_total),
                'refund_count' => (int) $r->refund_count,
                'refund_total' => $this->money($r->refund_total),
                'cancelled_count' => (int) $r->cancelled_count,
            ];
            $row['net_total'] = $this->money($row['sell_total'] - $row['refund_total']);
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }

            return $row;
        })->sortByDesc('net_total')->values()->all();

        $totals['net_total'] = $this->money($totals['sell_total'] - $totals['refund_total']);

        return ['date' => $from->toDateString(), 'shops' => $shops, 'totals' => $totals];
    }
}
