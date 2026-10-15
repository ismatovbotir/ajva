<?php

namespace App\Monitor;

/**
 * The standing comparison rule of the monitors. Every comparable figure is shown as
 * {now, prev_day, same_weekday_lw}, each cut at the SAME time of day, with two deltas:
 * A (against yesterday) and B (against the same weekday last week).
 *
 * Colouring (count based figures, i.e. everything built from receipts):
 *  - a delta is only shown (and coloured) when its baseline window has at least MIN_BASE receipts, else 'n/a';
 *  - ok    both deltas >= -5 %;
 *  - warn  either delta < -5 % and neither < -15 %;
 *  - bad   either delta < -15 %;
 *  - none  no usable delta at all (neutral colour).
 * 'incomplete' marks a week-ago day without any data (the baseline B is then not trustworthy).
 */
class Comparison
{
    public const MIN_BASE = 10;

    public const WARN_PCT = -5.0;

    public const BAD_PCT = -15.0;

    /**
     * @param  float|null  $prevDay  value of the previous day at the same time (null = not available)
     * @param  float|null  $weekAgo  value of the same weekday one week ago at the same time
     * @param  int  $prevBase  receipts in the previous-day window (the colouring gate)
     * @param  int  $weekBase  receipts in the week-ago window
     * @return array{now: float, prev_day: ?float, same_weekday_lw: ?float, a: ?float, b: ?float, status: string, incomplete: bool}
     */
    public static function make(float $now, ?float $prevDay, ?float $weekAgo, int $prevBase, int $weekBase, bool $weekDayHasData = true): array
    {
        $a = self::pct($now, $prevDay, $prevBase);
        $b = self::pct($now, $weekAgo, $weekBase);

        return [
            'now' => $now,
            'prev_day' => $prevDay,
            'same_weekday_lw' => $weekAgo,
            'a' => $a,
            'b' => $b,
            'status' => self::status($a, $b),
            'incomplete' => ! $weekDayHasData,
        ];
    }

    /** Percent change, or null when the baseline is empty or has fewer than MIN_BASE receipts. */
    public static function pct(float $now, ?float $base, int $baseReceipts): ?float
    {
        if ($base === null || $base <= 0 || $baseReceipts < self::MIN_BASE) {
            return null;
        }

        return ($now - $base) / $base * 100;
    }

    public static function status(?float $a, ?float $b): string
    {
        $deltas = array_filter([$a, $b], fn ($d) => $d !== null);
        if ($deltas === []) {
            return 'none';
        }

        $worst = min($deltas);

        return $worst < self::BAD_PCT ? 'bad' : ($worst < self::WARN_PCT ? 'warn' : 'ok');
    }

    /** Colour of a single delta (used for the small delta chips). */
    public static function deltaStatus(?float $pct): string
    {
        return $pct === null ? 'none' : ($pct < self::BAD_PCT ? 'bad' : ($pct < self::WARN_PCT ? 'warn' : 'ok'));
    }

    /**
     * Rates (refund, cancelled, discount): amber from 2 %, red from 5 % - and only with enough receipts.
     */
    public static function rateStatus(float $percent, int $receipts): string
    {
        if ($receipts < self::MIN_BASE) {
            return 'none';
        }

        return $percent >= 5.0 ? 'bad' : ($percent >= 2.0 ? 'warn' : 'ok');
    }

    /** Margin: amber when 2 points below a baseline, red at 5 points (either baseline). */
    public static function marginStatus(?float $now, ?float $prevDay, ?float $weekAgo): string
    {
        if ($now === null) {
            return 'none';
        }

        $gaps = array_filter([$prevDay, $weekAgo], fn ($b) => $b !== null);
        if ($gaps === []) {
            return 'none';
        }

        $worst = max(array_map(fn ($b) => $b - $now, $gaps));

        return $worst >= 5.0 ? 'bad' : ($worst >= 2.0 ? 'warn' : 'ok');
    }
}
