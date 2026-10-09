<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\InvalidArguments;
use App\Services\ReceiptAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReceiptAnalyticsReport extends BaseTool
{
    public function name(): string
    {
        return 'receipt_analytics';
    }

    public function description(): string
    {
        return 'Deep receipt analytics for a date range (max 92 days) and optional shops: KPIs (net sales, average check, basket size, discounts, refunds, cancelled), sale receipts by discount depth, discount by shop and by item, big receipts (top 5 % by total) with the items over-represented in them, and items bought together (support, confidence, lift). Successful sales only except where refunds/cancelled are named.';
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
        if ($from->diffInDays($to) >= 92) {
            throw new InvalidArguments('The period can not be longer than 92 days.');
        }

        $all = DB::table('shops')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requested = $arguments['shop_ids'] ?? null;
        if ($requested !== null && (! is_array($requested) || array_filter($requested, fn ($v) => ! is_int($v)) !== [])) {
            throw new InvalidArguments('shop_ids must be a list of integers.');
        }
        $shopIds = $requested === null ? $all : array_values(array_intersect($requested, $all));

        $report = app(ReceiptAnalytics::class)->build(Carbon::parse($from), Carbon::parse($to), $shopIds);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'shop_ids' => $shopIds,
            'report' => $report === [] ? null : $report,
        ];
    }
}
