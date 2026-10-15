<?php

namespace App\Mcp;

use App\Monitor\Comparison;
use App\Services\SalesMetrics;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

abstract class BaseTool implements Tool
{
    protected const DATE_SCHEMA = [
        'type' => 'string',
        'description' => 'Day as YYYY-MM-DD (POS receipt time). Defaults to today.',
        'pattern' => '^\d{4}-\d{2}-\d{2}$',
    ];

    protected const SHOP_SCHEMA = [
        'type' => 'integer',
        'description' => 'Limit to one shop id (see list_shops). Omit for all shops.',
    ];

    protected const SHOP_IDS_SCHEMA = [
        'type' => 'array',
        'items' => ['type' => 'integer'],
        'description' => 'Limit to these shop ids (see list_shops). Omit for all shops.',
    ];

    /**
     * Optional shop_ids list: null = all shops.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, int>|null
     */
    protected function shopIds(array $arguments): ?array
    {
        $requested = $arguments['shop_ids'] ?? null;
        if ($requested === null || $requested === []) {
            return null;
        }
        if (! is_array($requested) || array_filter($requested, fn ($v) => ! is_int($v)) !== []) {
            throw new InvalidArguments('shop_ids must be a list of integers.');
        }

        return array_values(array_unique($requested));
    }

    /**
     * An optional string argument that must be one of $allowed.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<int, string>  $allowed
     */
    protected function optionalEnum(array $arguments, string $key, array $allowed): ?string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArguments("{$key} must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    /** Free text from the database that might hold ids or tokens: long digit runs and UUIDs are masked, length capped. */
    protected function scrub(?string $text, int $max = 200): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $text = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '[hidden]', $text);
        $text = preg_replace('/\d{7,}/', '[hidden]', (string) $text);

        return mb_substr((string) $text, 0, $max);
    }

    protected function stamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i');
    }

    /** Keeps the main warehouse out of sales queries when no single shop was asked for (the sales boards do the same). */
    protected function withoutWarehouse($query, string $column, ?int $shopId)
    {
        $warehouseId = Warehouse::shopId();

        return $shopId === null && $warehouseId !== null ? $query->where($column, '!=', $warehouseId) : $query;
    }

    /**
     * The three windows of the comparison rule for a day, cut at the same time of day: a past day is compared
     * whole, today up to the current time. Keys today / yesterday / week (see SalesMetrics::windows).
     *
     * @return array{today: array{0: Carbon, 1: Carbon}, yesterday: array{0: Carbon, 1: Carbon}, week: array{0: Carbon, 1: Carbon}}
     */
    protected function comparisonWindows(Carbon $dayStart): array
    {
        return SalesMetrics::windows($dayStart->isToday() ? now() : $dayStart->copy()->endOfDay());
    }

    /**
     * Successful sale receipts (active and sell) of a window: shop id => [receipts, total].
     *
     * @return array<int, array{0: int, 1: float}>
     */
    protected function saleTotals(Carbon $from, Carbon $to, ?int $shopId): array
    {
        $rows = $this->withoutWarehouse(DB::table('receipts'), 'shop_id', $shopId)
            ->where('active', true)->where('sell', true)
            ->whereBetween('created_at', [$from, $to])
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->groupBy('shop_id')
            ->selectRaw('shop_id, COUNT(*) AS c, SUM(total) AS s')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->shop_id] = [(int) $r->c, (float) $r->s];
        }

        return $out;
    }

    /**
     * One comparison block per the standing rule: yesterday and the same weekday last week, cut at the same time of
     * day, with percent deltas that are null while the baseline has fewer than Comparison::MIN_BASE receipts.
     *
     * @param  array{0: int, 1: float}  $now
     * @param  array{0: int, 1: float}  $prev
     * @param  array{0: int, 1: float}  $week
     * @return array<string, mixed>
     */
    protected function comparisonBlock(array $now, array $prev, array $week): array
    {
        $pct = fn (array $base) => ($d = Comparison::pct($now[1], $base[1], $base[0])) === null ? null : round($d, 1);

        return [
            'prev_day' => ['receipts' => $prev[0], 'total' => $this->money($prev[1])],
            'same_weekday_lw' => ['receipts' => $week[0], 'total' => $this->money($week[1])],
            'delta_prev_day_pct' => $pct($prev),
            'delta_same_weekday_lw_pct' => $pct($week),
            'week_ago_has_no_receipts' => $week[0] === 0,
        ];
    }

    /** Header for a comparison: which days and the cut-off time (deltas need >= 10 receipts in the baseline). */
    protected function comparisonHeader(array $windows): array
    {
        return [
            'cut_time' => $windows['today'][1]->format('H:i'),
            'prev_day' => $windows['yesterday'][0]->toDateString(),
            'same_weekday_lw' => $windows['week'][0]->toDateString(),
            'note' => 'Sale receipts (active and sell) up to the same time of day. A delta is null when its baseline has fewer than '.Comparison::MIN_BASE.' receipts.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function dayRange(array $arguments): array
    {
        $raw = $arguments['date'] ?? null;

        if ($raw === null || $raw === '') {
            $day = now()->startOfDay();
        } else {
            try {
                $day = Carbon::createFromFormat('!Y-m-d', (string) $raw);
            } catch (\Throwable) {
                $day = false;
            }

            if (! $day || $day->format('Y-m-d') !== $raw) {
                throw new InvalidArguments('date must be a valid day formatted as YYYY-MM-DD.');
            }
        }

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function optionalInt(array $arguments, string $key, ?int $min = null, ?int $max = null): ?int
    {
        if (! isset($arguments[$key])) {
            return null;
        }

        $value = $arguments[$key];
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArguments("{$key} must be an integer.");
        }

        $value = (int) $value;
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            throw new InvalidArguments("{$key} must be between {$min} and {$max}.");
        }

        return $value;
    }

    protected function hourExpression(string $column = 'receipts.created_at'): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', {$column}) AS INTEGER)"
            : "HOUR({$column})";
    }

    protected function money(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }
}
