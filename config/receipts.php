<?php

/*
|--------------------------------------------------------------------------
| Receipt data health and validation
|--------------------------------------------------------------------------
|
| Wrong receipt data means wrong order quantities, so the feed is checked on the way in. Everything is CONSERVATIVE:
| a receipt is REJECTED (422) only when it is clearly impossible; anything merely suspicious is ACCEPTED and flagged
| (receipts.dq_flags, a comma list of the codes below) so the data-health board and the Security warnings can count it.
|
| strict_validation  true (default): reject qty = 0 on a (non-storno) position, |qty| above max_qty and a receipt dated
|                    more than future_days days ahead. false = the older behaviour (nothing of this is rejected).
| flagging           true (default): compute dq_flags at ingestion. false = never flag (column stays NULL).
| max_qty            a single position quantity above this is not a real number (typed-in or unit mistake).
| future_days        a receipt time later than now + this many days is a broken till clock.
| old_years          an open date older than this many years is flagged `old_date` (never rejected: the 1C history
|                    migration legitimately brings years of old receipts).
| item_max_multiple  qty above this x the item's max order rule of the shop is flagged `qty_gt_max`.
| keep_rejections    how many rejected receipts are kept (newest) in receipt_rejections; per-day counters are kept longer.
|
| Flag codes (dq_flags): neg_qty (negative qty on a sale line), bad_total (total <= 0 on a sale receipt),
| sum_lt_total (sum before discount below the total), qty_gt_max (qty > item_max_multiple x item max),
| old_date (open date older than old_years).
|
| Data-health board (/data-health):
|   late_hours         a receipt that reaches us more than this many hours after its own time counts as late / backfilled.
|   lag_amber_hours    feed lag (now - newest receipt) amber / red; a shop sleeps at night, so these are long.
|   lag_red_hours
|   low_ratio          receipts of the last 24 h below this share of the usual day = amber (silent below
|                      inventory.feed_silence_min_ratio of the usual day, red).
|   min_usual          the usual day needs at least this many sale receipts before a shop is judged.
|   spike_min          rejections in 24 h from which a spike is judged, and spike_ratio x the earlier daily average.
|   cache_seconds      the board is cached this long (with the sales version).
|
*/

return [

    'strict_validation' => filter_var(env('RECEIPTS_STRICT_VALIDATION', true), FILTER_VALIDATE_BOOLEAN),

    'flagging' => filter_var(env('RECEIPTS_FLAGGING', true), FILTER_VALIDATE_BOOLEAN),

    'max_qty' => (float) env('RECEIPTS_MAX_QTY', 100000),

    'future_days' => (int) env('RECEIPTS_FUTURE_DAYS', 1),

    'old_years' => (int) env('RECEIPTS_OLD_YEARS', 5),

    'item_max_multiple' => (float) env('RECEIPTS_ITEM_MAX_MULTIPLE', 10),

    'keep_rejections' => 500,

    'late_hours' => 48,

    'health' => [
        'lag_amber_hours' => 12,
        'lag_red_hours' => 24,
        'low_ratio' => 0.5,
        'min_usual' => 20,
        'spike_min' => 10,
        'spike_ratio' => 3.0,
        'cache_seconds' => 60,
    ],
];
