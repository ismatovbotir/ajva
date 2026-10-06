<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use Illuminate\Support\Facades\DB;

class HourlySales extends BaseTool
{
    public function name(): string
    {
        return 'hourly_sales';
    }

    public function description(): string
    {
        return 'Successful sales by hour (00-23) for one day: receipt count and total, with a per-shop breakdown. Hours without sales are omitted.';
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
        $hour = $this->hourExpression('created_at');

        $rows = DB::table('receipts')
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

        return ['date' => $from->toDateString(), 'hours' => array_values($hours)];
    }
}
