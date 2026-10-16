<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\InvalidArguments;
use App\Services\AssortmentAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AssortmentAnalysisReport extends BaseTool
{
    public function name(): string
    {
        return 'assortment_analysis';
    }

    public function description(): string
    {
        return 'Product matrix for a date range (7 to 92 days, 12 full weeks recommended) and optional shops: ABC classes by revenue share (A = first 80 % of sales, B = next 15 %, C = the rest), XYZ classes by variation of weekly sales (needs 8 full weeks), the 3x3 matrix with item counts and revenue shares, class A items out of stock in at least one shop, dead stock (stock but no sales in the period), slow movers (more than 90 days of cover) and the top items with their class. Successful sales only. No cost or profit figures.';
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

        return [
            'shop_ids' => $shopIds,
            'report' => app(AssortmentAnalysis::class)->build(Carbon::parse($from), Carbon::parse($to), $shopIds, false),
        ];
    }
}
