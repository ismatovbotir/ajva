<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HourlySales extends BaseTool
{
    public function name(): string
    {
        return 'hourly_sales';
    }

    public function description(): string
    {
        return 'Successful sales by hour (00-23) for one day: receipt count and total, with a per-shop breakdown. Hours without sales are omitted. The main warehouse shop is left out unless asked for by shop_id. With compare=true it adds the same hourly series for yesterday and for the same weekday last week, cut at the same time of day, with percent deltas of the total.';
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
        $hour = $this->hourExpression('created_at');

        $rows = $this->withoutWarehouse(DB::table('receipts'), 'shop_id', $shopId)
            ->where('active', true)
            ->where('sell', true)
            ->whereBetween('created_at', [$from, $to])
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->groupBy('shop_id', 'hr')
            ->orderBy('hr')
            ->selectRaw("shop_id, {$hour} AS hr, COUNT(*) AS c, SUM(total) AS s")
            ->get();

        $names = DB::table('shops')->pluck('name', 'id');
        $hours = [];
        foreach ($rows as $r) {
            $key = sprintf('%02d', (int) $r->hr);
            $hours[$key] ??= ['hour' => $key, 'receipts' => 0, 'total' => 0.0, 'by_shop' => []];
            $hours[$key]['receipts'] += (int) $r->c;
            $hours[$key]['total'] = $this->money($hours[$key]['total'] + (float) $r->s);
            $hours[$key]['by_shop'][] = [
                'shop_id' => (int) $r->shop_id,
                'shop' => $names[$r->shop_id] ?? '#'.$r->shop_id,
                'receipts' => (int) $r->c,
                'total' => $this->money($r->s),
            ];
        }
        ksort($hours);

        $result = ['date' => $from->toDateString(), 'hours' => array_values($hours)];

        if (! empty($arguments['compare'])) {
            $windows = $this->comparisonWindows($from);
            $now = $this->hourlyTotals(...$windows['today'], shopId: $shopId);
            $result['comparison'] = $this->comparisonHeader($windows);
            $blocks = [];
            foreach (['prev_day' => 'yesterday', 'same_weekday_lw' => 'week'] as $key => $window) {
                $blocks[$key] = $this->hourlyTotals(...$windows[$window], shopId: $shopId);
                $result['comparison'][$key] = [
                    'date' => $windows[$window][0]->toDateString(),
                    'receipts' => array_sum(array_column($blocks[$key], 'receipts')),
                    'total' => $this->money(array_sum(array_column($blocks[$key], 'total'))),
                    'hours' => array_values($blocks[$key]),
                ];
            }
            $sum = fn (array $set) => [array_sum(array_column($set, 'receipts')), (float) array_sum(array_column($set, 'total'))];
            $block = $this->comparisonBlock($sum($now), $sum($blocks['prev_day']), $sum($blocks['same_weekday_lw']));
            $result['comparison']['delta_prev_day_pct'] = $block['delta_prev_day_pct'];
            $result['comparison']['delta_same_weekday_lw_pct'] = $block['delta_same_weekday_lw_pct'];
            $result['comparison']['week_ago_has_no_receipts'] = $block['week_ago_has_no_receipts'];
        }

        return $result;
    }

    /**
     * Sale receipts per hour in a window over the chosen shops.
     *
     * @return array<string, array{hour: string, receipts: int, total: float}>
     */
    private function hourlyTotals(Carbon $from, Carbon $to, ?int $shopId): array
    {
        $hour = $this->hourExpression('created_at');
        $rows = $this->withoutWarehouse(DB::table('receipts'), 'shop_id', $shopId)
            ->where('active', true)->where('sell', true)
            ->whereBetween('created_at', [$from, $to])
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->groupBy('hr')->orderBy('hr')
            ->selectRaw("{$hour} AS hr, COUNT(*) AS c, SUM(total) AS s")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = sprintf('%02d', (int) $r->hr);
            $out[$key] = ['hour' => $key, 'receipts' => (int) $r->c, 'total' => $this->money($r->s)];
        }

        return $out;
    }
}
