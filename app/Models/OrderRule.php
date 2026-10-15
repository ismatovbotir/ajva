<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Order rules of a shop for the forecast and the automatic daily draft (orders:auto-generate).
 * The row with shop_id NULL is the chain default; a shop row only overrides the columns it sets
 * (NULL = inherit), so the run state (last_run_*) can live per shop without breaking inheritance.
 * Without any rows the built-in defaults apply and nothing runs automatically (enabled = false).
 *
 * last_run_date is written with query-builder updates only (OrderRule::claim, ::recordRun) and
 * deliberately not cast: the atomic claim compares plain 'Y-m-d' strings.
 */
class OrderRule extends Model
{
    public const HISTORY_OPTIONS = [14, 21, 28];

    public const DEFAULT_HISTORY_DAYS = 28;

    public const DEFAULT_RUN_TIME = '08:00';

    protected $fillable = [
        'shop_id',
        'enabled',
        'history_days',
        'horizon_days',
        'run_time',
        'weekdays',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'weekdays' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public static function defaultRow(): ?self
    {
        return self::query()->whereNull('shop_id')->first();
    }

    /** Horizon (1-7) of the chain default: the default row, else config inventory.order_horizon_days. */
    public static function defaultHorizon(): int
    {
        $days = self::query()->whereNull('shop_id')->value('horizon_days');

        return max(1, min(7, (int) ($days ?: config('inventory.order_horizon_days', 3))));
    }

    /**
     * The settings that apply to each shop (own value, else the default row, else the built-in default).
     *
     * @param  array<int, int>  $shopIds
     * @return array<int, array<string, mixed>> shop id => {shop_id, rule_id (own row or null), enabled, history_days,
     *                                          horizon_days, run_time, weekdays (list, [] = every day),
     *                                          last_run_date (Y-m-d|null), last_run_status, last_run_note}
     */
    public static function effective(array $shopIds): array
    {
        $rows = self::query()->whereNull('shop_id')->orWhereIn('shop_id', $shopIds)->get();
        $default = $rows->first(fn (self $r) => $r->shop_id === null);
        $own = $rows->filter(fn (self $r) => $r->shop_id !== null)->keyBy('shop_id');

        $pick = function (?self $row, string $column, mixed $fallback) use ($default) {
            return $row?->{$column} ?? $default?->{$column} ?? $fallback;
        };

        $out = [];
        foreach ($shopIds as $id) {
            $id = (int) $id;
            $row = $own->get($id);
            $history = (int) $pick($row, 'history_days', self::DEFAULT_HISTORY_DAYS);
            $horizon = (int) ($pick($row, 'horizon_days', null) ?: config('inventory.order_horizon_days', 3));

            $out[$id] = [
                'shop_id' => $id,
                'rule_id' => $row?->id,
                'enabled' => (bool) $pick($row, 'enabled', false),
                'history_days' => in_array($history, self::HISTORY_OPTIONS, true) ? $history : self::DEFAULT_HISTORY_DAYS,
                'horizon_days' => max(1, min(7, $horizon)),
                'run_time' => (string) $pick($row, 'run_time', self::DEFAULT_RUN_TIME),
                'weekdays' => array_values(array_map('intval', (array) $pick($row, 'weekdays', []))),
                'last_run_date' => $row?->last_run_date === null ? null : substr((string) $row->last_run_date, 0, 10),
                'last_run_status' => $row?->last_run_status,
                'last_run_note' => $row?->last_run_note,
            ];
        }

        return $out;
    }

    /** @param  array<int, int>  $shopIds  @return array<int, int> shop id => history window in days */
    public static function historyDays(array $shopIds): array
    {
        return array_map(fn (array $e) => $e['history_days'], self::effective($shopIds));
    }

    /** @param  array<int, int>  $shopIds  @return array<int, int> shop id => order horizon in days */
    public static function horizons(array $shopIds): array
    {
        return array_map(fn (array $e) => $e['horizon_days'], self::effective($shopIds));
    }

    /** Changes whenever a history window or a horizon changes: part of the forecast / plan cache keys. */
    public static function signature(): string
    {
        return substr(md5(self::query()->orderBy('id')->get(['id', 'history_days', 'horizon_days'])->toJson()), 0, 10);
    }

    /** The shop's own row, created (inheriting everything) on first use. */
    public static function ownRow(int $shopId): self
    {
        return self::query()->firstOrCreate(['shop_id' => $shopId]);
    }

    /**
     * Atomically takes today's run: true for exactly one caller when last_run_date is empty or before $today.
     */
    public static function claim(int $ruleId, string $today): bool
    {
        return self::query()->where('id', $ruleId)
            ->where(fn ($q) => $q->whereNull('last_run_date')->orWhere('last_run_date', '<', $today))
            ->update(['last_run_date' => $today, 'last_run_status' => 'running', 'last_run_note' => null]) === 1;
    }

    /** Stores the outcome of a run attempt (the date is only set by claim()). */
    public static function recordRun(int $ruleId, string $status, ?string $note = null): void
    {
        self::query()->where('id', $ruleId)->update(['last_run_status' => $status, 'last_run_note' => $note]);
    }

    /** Appends a sentence to the last run note of a shop (e.g. the Telegram result). */
    public static function appendNote(int $shopId, string $text): void
    {
        $row = self::query()->where('shop_id', $shopId)->first();
        if ($row !== null) {
            self::recordRun($row->id, (string) $row->last_run_status, trim(((string) $row->last_run_note).' '.$text));
        }
    }
}
