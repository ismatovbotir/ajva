<?php

/*
|--------------------------------------------------------------------------
| Sales growth > Sales gaps (App\Services\SalesGaps)
|--------------------------------------------------------------------------
|
| Every threshold and factor of the page lives here (also printed in the page's "How this is calculated" block).
| Money figures are ESTIMATES against the chain's own benchmark, never promises, and the levers overlap (a better
| check, more card customers and fewer stock-outs partly act on the same receipts), so they are never added up.
|
*/

return [

    /** Period: default length (whole days ending yesterday), shortest and longest allowed. */
    'default_days' => 28,
    'min_days' => 7,
    'max_days' => 92,

    /** How long a built report is cached (every Generate press builds a fresh one). */
    'cache_seconds' => 600,

    /** Money per month = per this many days. */
    'month_days' => 30,

    /** Benchmark: percentile of the shop values (0-100) and the shops needed for it, else the median. */
    'benchmark_percentile' => 75,
    'benchmark_min_shops' => 5,
    /** Below this many qualifying shops no benchmark is made at all (a median of one shop is the shop itself). */
    'benchmark_floor_shops' => 2,

    /** A shop (and a benchmark shop) needs this many sale receipts in the period before a rate is judged. */
    'min_receipts' => 200,

    /** Average check lever. */
    'check' => [
        /** Share of the gap to the benchmark that is realistically recoverable. */
        'recoverable_factor' => 0.3,
        /** The recoverable money is also shown capped at this share of the shop's own monthly sales. */
        'cap_share' => 0.05,
        /** A shop is flagged when its check is more than this many per cent below the benchmark. */
        'flag_gap_pct' => 8,
    ],

    /** Customer (loyalty card) share lever. */
    'card' => [
        /** The chain needs this many card receipts in the period before the lever is shown. */
        'min_chain_card_receipts' => 200,
        /** The uplift is an upper bound from a correlation: multiplied by this damping factor. */
        'damping' => 0.5,
        /** A shop is flagged when its share is at least this many percentage points below the target. */
        'min_gap_points' => 5,
    ],

    /** Class A availability lever. */
    'availability' => [
        /** Share of the lost sales that is realistically recoverable once the item is back. */
        'recoverable_share' => 0.7,
        /** Items listed per shop. */
        'top_items' => 10,
        /** Usable days of history needed before money is shown (below it the item is "unknown"). */
        'min_usable_days' => 10,
    ],

    /** Peak-hour signal (no money). */
    'peak' => [
        /** Receipts of the hour must exceed this multiple of the median of the same weekday and hour on the other days. */
        'ratio' => 1.5,
        /** Active registers (distinct tills with a receipt) in that hour: at most this many. */
        'max_active_registers' => 1,
        /** Only shops with at least this many registers are checked. */
        'min_registers' => 2,
        /** The pattern must repeat on at least this many days of the period. */
        'min_days' => 3,
        /** Receipts in the hour needed to count as a peak, and other days needed for the median. */
        'min_receipts' => 10,
        'min_other_days' => 3,
    ],

];
