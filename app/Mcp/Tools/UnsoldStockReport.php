<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\InvalidArguments;
use App\Services\UnsoldStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UnsoldStockReport extends BaseTool
{
    public function name(): string
    {
        return 'unsold_stock';
    }

    public function description(): string
    {
        return 'Stock without sales for a date range (7 to 92 days, 60 to 90 recommended) and optional shops: items that are in stock (current 1C snapshot) but had no successful sale in the period, with units on hand, shops holding them, groups with the most unsold items, and the same items per shop (stock in a shop that did not sell it there). Top 5000 rows, totals are exact. No cost or profit figures.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'First day as YYYY-MM-DD.', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'to' => ['type' => 'string', 'description' => 'Last day as YYYY-MM-DD (inclusive). Defaults to today.', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'shop_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Limit to these shop ids (see list_shops). Omit for all shops.'],
            ],
            'required' => ['from'],
        ];
    }

    public function handle(array $arguments): array
    {
        [$from] = $this->dayRange(['date' => $arguments['from'] ?? null]);
        [, $to] = $this->dayRange(['date' => $arguments['to'] ?? null]);

        if ($to->lt($from)) {
            throw new InvalidArguments('to must not be before from.');
        }
        $days = $from->diffInDays($to) + 1;
        if ($days > 92 || $days < 7) {
            throw new InvalidArguments('The period must be between 7 and 92 days.');
        }

        $all = DB::table('shops')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requested = $arguments['shop_ids'] ?? null;
        if ($requested !== null && (! is_array($requested) || array_filter($requested, fn ($v) => ! is_int($v)) !== [])) {
            throw new InvalidArguments('shop_ids must be a list of integers.');
        }
        $shopIds = $requested === null ? $all : array_values(array_intersect($requested, $all));

        $report = app(UnsoldStock::class)->build(Carbon::parse($from), Carbon::parse($to), $shopIds, false);
        // The model-facing list is capped further: the page has the full one.
        $report['items'] = array_slice($report['items'], 0, 200);
        $report['pairs'] = array_slice($report['pairs'], 0, 200);

        return ['shop_ids' => $shopIds, 'report' => $report];
    }
}
