<?php

namespace App\Monitor;

use App\Services\SalesMetrics;

/**
 * Small presentation helpers for the monitor widget components: which diagram is actually drawn
 * (with the documented fallbacks) and the status colour classes. Full class names live here as
 * literals so the Tailwind scanner finds them.
 */
class Render
{
    public const DONUT_MAX_SHOPS = 7;

    /** The diagram to draw for a payload: the chosen one unless the data cannot honour it. */
    public static function diagram(array $w): string
    {
        $d = $w['diagram'] ?? 'kpi';
        $items = $w['items'] ?? null;

        return match (true) {
            // Few points do not make a line: a single number tells more.
            $d === 'line' && isset($w['lines']) && count($w['labels'] ?? []) < 3 => 'kpi',
            $d === 'donut' && $items !== null && count($items) > self::DONUT_MAX_SHOPS => 'bar',
            $d === 'sparkline' && count($w['spark'] ?? []) < 3 => 'kpi',
            $d === 'gauge' && ! isset($w['gauge']) => 'kpi',
            default => $d,
        };
    }

    /**
     * Slices for a donut / stacked bar: the payload's own parts, or the shares of a by-shop list (at most 6 + Other).
     *
     * @return array<int, array{label: string, value: float, text: string, color: string}>
     */
    public static function parts(array $w): array
    {
        if (isset($w['parts'])) {
            return $w['parts'];
        }

        $items = $w['items'] ?? [];
        $total = array_sum(array_column($items, 'value')) ?: 1.0;
        $parts = [];
        foreach (array_slice($items, 0, 6) as $i => $item) {
            $parts[] = ['label' => $item['label'], 'value' => $item['value'] / $total * 100, 'text' => $item['text'], 'color' => SalesMetrics::COLORS[$i] ?? SalesMetrics::COLOR_OTHER];
        }
        if (count($items) > 6) {
            $rest = array_sum(array_column(array_slice($items, 6), 'value'));
            $parts[] = ['label' => __('Other'), 'value' => $rest / $total * 100, 'text' => number_format($rest, 0, '.', ' '), 'color' => SalesMetrics::COLOR_OTHER];
        }

        return $parts;
    }

    /** Tile border / background by status. */
    public static function tile(string $status): string
    {
        return match ($status) {
            'bad' => 'border-red-500/60 bg-red-500/[0.06]',
            'warn' => 'border-amber-500/50 bg-amber-500/[0.06]',
            default => 'border-slate-800 bg-slate-900',
        };
    }

    /** Text colour by status (neutral statuses return $neutral). */
    public static function text(string $status, string $neutral = 'text-slate-400'): string
    {
        return match ($status) {
            'bad' => 'text-red-400',
            'warn' => 'text-amber-300',
            'ok' => 'text-emerald-400',
            default => $neutral,
        };
    }

    /** Top of a value axis: the next "round" number (1, 1.2, 1.5, 2, 2.5, ... 10 x 10^k) at or above the data maximum. */
    public static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 1.0;
        }
        $mag = 10 ** floor(log10($max));
        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
            if ($max <= $m * $mag) {
                return (float) ($m * $mag);
            }
        }

        return (float) (10 * $mag);
    }

    /** Short axis figure: 1.2M, 450k, 80. */
    public static function compact(float $v): string
    {
        $abs = abs($v);
        $trim = fn (float $n) => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

        return match (true) {
            $abs >= 1_000_000 => $trim($v / 1_000_000).'M',
            $abs >= 1_000 => $trim($v / 1_000).'k',
            default => $trim($v),
        };
    }

    /** Delta chip (pill) by status. */
    public static function chip(string $status): string
    {
        return match ($status) {
            'bad' => 'bg-red-500/15 text-red-400',
            'warn' => 'bg-amber-500/15 text-amber-300',
            'ok' => 'bg-emerald-500/15 text-emerald-400',
            default => 'bg-slate-500/15 text-slate-400',
        };
    }

    /** SVG stroke colour by status. */
    public static function stroke(string $status): string
    {
        return match ($status) {
            'bad' => 'stroke-red-500',
            'warn' => 'stroke-amber-500',
            'ok' => 'stroke-emerald-500',
            default => 'stroke-slate-300',
        };
    }

    /** Bar fill by status. */
    public static function fill(string $status): string
    {
        return match ($status) {
            'bad' => 'bg-red-500',
            'warn' => 'bg-amber-500',
            'ok' => 'bg-emerald-500',
            default => 'bg-slate-400',
        };
    }

    /** Series colours (literal hex, like the sales charts: one colour per meaning in both themes). */
    public static function tone(string $tone): string
    {
        return match ($tone) {
            'prev' => '#a78bfa',
            'week' => '#898781',
            default => '#2a78d6',
        };
    }

    /** Heatmap cell background: sequential (0..1) or diverging (-1..1, null = no data). */
    public static function heat(?float $v, string $kind): string
    {
        if ($v === null) {
            return 'background: rgba(148,163,184,.08)';
        }

        if ($kind === 'div') {
            $a = number_format(0.12 + 0.55 * min(1, abs($v)), 2, '.', '');

            return $v >= 0 ? "background: rgba(27,175,122,{$a})" : "background: rgba(194,58,58,{$a})";
        }

        return 'background: rgba(42,120,214,'.number_format(0.1 + 0.6 * max(0, min(1, $v)), 2, '.', '').')';
    }
}
