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
        return 'Receipt counts and totals per shop for one day: successful sales, successful refunds and cancelled receipts, plus net total (sales minus refunds). The main warehouse shop is left out unless asked for by shop_id. With compare=true the totals (and each shop) also carry the sale count and total of yesterday and of the same weekday last week at the same time of day, with percent deltas.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => self::DATE_SCHEMA,
                'shop_id' => self::SHOP_SCHEMA,
                'compare' => ['type' => 'boolean', 'description' => 'Also compare with yesterday and with the same weekday last week, cut at the same time of day (today: up to now; a past day: whole days). Percent deltas are null when the baseline has fewer than 10 receipts.'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        [$from, $to] = $this->dayRange($arguments);
        $shopId = $this->optionalInt($arguments, 'shop_id', 1);

        $rows = $this->withoutWarehouse(DB::table('receipts'), 'shop_id', $shopId)
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

        $result = ['date' => $from->toDateString(), 'shops' => $shops, 'totals' => $totals];

        if (! empty($arguments['compare'])) {
            $windows = $this->comparisonWindows($from);
            $now = $this->saleTotals(...$windows['today'], shopId: $shopId);
            $prev = $this->saleTotals(...$windows['yesterday'], shopId: $shopId);
            $week = $this->saleTotals(...$windows['week'], shopId: $shopId);

            $sum = fn (array $set) => [array_sum(array_column($set, 0)), array_sum(array_column($set, 1))];
            $result['comparison'] = $this->comparisonHeader($windows);
            $result['totals']['comparison'] = $this->comparisonBlock($sum($now), $sum($prev), $sum($week));
            foreach ($result['shops'] as $i => $row) {
                $id = $row['shop_id'];
                $result['shops'][$i]['comparison'] = $this->comparisonBlock($now[$id] ?? [0, 0.0], $prev[$id] ?? [0, 0.0], $week[$id] ?? [0, 0.0]);
            }
        }

        return $result;
    }
}
