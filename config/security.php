<?php

/*
|--------------------------------------------------------------------------
| Security warnings from receipts (Security > Security warnings)
|--------------------------------------------------------------------------
|
| Every warning is a SIGNAL TO REVIEW, never proof. A rate is judged only above a minimum sample, and always against
| the "rest of the chain" (everybody else in the same period and shops), never against a magic number alone.
|
| Severity (rate based types: refunds, cancelled, empty cancelled, paid cancelled, deep discounts):
|   flagged  sample >= min_receipts AND events >= min_events AND rate >= the type floor AND rate >= ratio_flag x baseline
|   high     sample >= high_sample AND rate >= ratio_high x baseline AND rate >= the type high floor
|   medium   flagged with sample >= high_sample, but not high
|   low      flagged with sample between min_receipts and high_sample
| A baseline of 0 (nobody else has such events) counts as an infinite ratio, so the floors alone decide.
|
*/

return [

    /** A person / till needs at least this many receipts in the period before any rate is judged. */
    'min_receipts' => 20,

    /** The rest of the chain needs at least this many receipts to be a baseline (else the rate is not compared). */
    'min_baseline_receipts' => 20,

    /** At least this many events (refunds, cancelled receipts, deep discounts) behind a flagged rate. */
    'min_events' => 3,

    /** Sample size from which a flag is medium or high instead of low. */
    'high_sample' => 30,

    /** Rate must be at least this multiple of the rest-of-chain rate to be flagged / to be high. */
    'ratio_flag' => 2.0,
    'ratio_high' => 3.0,

    /** Rate floors in per cent: [flag, high]. */
    'floors' => [
        'refund_amount' => [2.0, 5.0],   // refund amount / sales amount
        'refund_count' => [2.0, 5.0],    // refund receipts / (sale + refund receipts)
        'cancelled' => [2.0, 5.0],       // cancelled receipts / all receipts
        'cancelled_empty' => [2.0, 5.0], // cancelled receipts without lines / all receipts
        'cancelled_paid' => [1.0, 3.0],  // cancelled receipts that carry a payment / all receipts
        'discount_deep' => [5.0, 15.0],  // deep-discount sale receipts / sale receipts
    ],

    /** Refunds: lines of the same item refunded by the same cashier this many times or more. */
    'repeat_refund_min' => 3,
    'repeat_refund_high' => 6,

    /** Refund lines without a sale of that item in the same shop within N days before the refund (needs that history). */
    'refund_lookback_days' => 30,
    'unmatched_refund_min' => 3,
    'unmatched_refund_high' => 6,

    /** A sale receipt is "deep discount" at or above this discount depth: discount / (total + discount), in per cent. */
    'deep_discount_pct' => 30.0,

    /** Deep discounts in the last N minutes before the shop's last sale of the day (days with enough receipts only). */
    'end_of_day_minutes' => 60,
    'end_of_day_min_day_receipts' => 20,
    'end_of_day_min' => 3,
    'end_of_day_high' => 6,

    /** Off hours: an hour with less than this share (per cent) of the shop's sale receipts of the last baseline_days. */
    'baseline_days' => 28,
    'off_hours_share_pct' => 1.0,
    'off_hours_min_baseline_receipts' => 200, // the shop's own volume needed to know its normal hours
    'off_hours_medium' => 3,
    'off_hours_high' => 10,

    /** Receipt number gaps inside a (till, shift): the shift needs this many receipts to be judged. */
    'gap_min_receipts' => 10,
    'gap_medium_missing' => 3,
    'gap_high_missing' => 10,
    'gap_high_share_pct' => 10.0,

    /** Duplicate looking receipts: same till + number + total in different shifts this close in time. */
    'duplicate_seconds' => 60,

    /** Silent shop: consecutive usual-opening hours without any sale receipt. */
    'silent_hours' => 3,
    'silent_high_hours' => 6,
    'silent_min_expected' => 10,      // receipts the same weekday median would have had in those hours
    'silent_min_weekday_days' => 3,   // other same-weekday days needed for the median

    /** Data integrity. */
    'future_minutes' => 10,
    'data_min_events_high' => 10,
    'data_high_share_pct' => 1.0,

    /** Unusual basket: one line, qty at least this, and total at least N x the shop's big-receipt cut (p95 of sale totals). */
    'basket_min_receipts' => 20,
    'basket_cut_top_share' => 0.05,
    'basket_cut_multiplier' => 2.0,
    'basket_high_multiplier' => 5.0,
    'basket_min_qty' => 10,

    /** Cashier drill-down. */
    'detail_days' => 30,
    'detail_per_page' => 25,

    /** Telegram digest (security:digest): time of day and look-back. */
    'digest_time' => env('SECURITY_DIGEST_TIME', '08:45'),
    'digest_hours' => 24,

    'cache_seconds' => 600,
    'max_days' => 92,
];
