<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cost price id
    |--------------------------------------------------------------------------
    |
    | 1C price type whose value is an item's cost (purchase) price. Every
    | other price type is a selling price. Margin calculations read cost from
    | item_prices.price_id = this id, and the items sync marks prices.is_sell
    | from it, so the flag no longer depends on what 1C sends.
    |
    */

    'cost_price_id' => (int) env('COST_PRICE_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Explicit zero stock (1C reports zero itself)
    |--------------------------------------------------------------------------
    |
    | true (default): only a real quantity <= 0 in the shop's latest sync is "out of stock". A pair whose latest row is
    |   older than the shop's latest sync date (or that never had a row) is "not reported by 1C" (state no_data): not in
    |   stock, not out of stock, not counted as zero, no order suggestion. See App\Models\Stock (applyReported).
    | false: legacy inference (a pair keeps its last quantity; the warehouse list has the "missing" reason and the
    |   partial-sync coverage guard; the forecast marks old rows "stale").
    |
    */

    'explicit_zero_stock' => filter_var(env('EXPLICIT_ZERO_STOCK', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Ordering / forecast
    |--------------------------------------------------------------------------
    |
    | order_horizon_days  days of sales the order must cover (1-7): the default of
    |                     the Ordering page and the horizon of the warehouse monitor.
    | ordering_cache_seconds  how long the heavy forecast is cached (Refresh bypasses it).
    | outlier_mad_factor  daily sales above median + factor x MAD are clipped per item.
    |
    */

    'order_horizon_days' => (int) env('ORDER_HORIZON_DAYS', 3),

    'ordering_cache_seconds' => (int) env('ORDERING_CACHE_SECONDS', 300),

    'outlier_mad_factor' => (float) env('OUTLIER_MAD_FACTOR', 3),

    /*
    |--------------------------------------------------------------------------
    | Forecast model switches (ForecastService)
    |--------------------------------------------------------------------------
    |
    | Every switch has a 'legacy' value that reproduces the older numbers exactly.
    |
    | weekday_shape   'window' (default): level = recent speed, shape(wd) = weekday mean / overall mean over the
    |                 whole history window (14/21/28 days of the shop's order rule), shrunk with n/(n+2);
    |                 f(d) = level x shape(wd). 'legacy': weekday means only from the (7/14/28 day) speed window,
    |                 f = wd_mean x L + speed x (1 - L); cover_days = position / flat speed.
    | clip_mode       'weekday' (default): a day is clipped only above max(median + k x MAD, 2 x the mean of the OTHER
    |                 days with the same weekday), so real weekend / pay-day peaks survive; a day clipped to
    |                 <= 1/3 of its value sets the row flag `spike`. 'legacy': median + k x MAD only.
    | safety_stock    true (default): for confidence ok / high the target is max(min, SS) + F(H + L) with
    |                 SS = z x sigma_daily x sqrt(H + L), capped at max x max_overshoot. false: min + F(H).
    | lead_time_days  days between the order and the goods on the shelf (0 = same-day delivery, 0-7).
    | service_z       z per ABC class (service level A 95 %, B 90 %, C 80 %); 'default' for any other class.
    | max_overshoot   the safety-stock uplift never lifts the target above max x this (<= 0 = no cap).
    |
    */

    'weekday_shape' => in_array(env('WEEKDAY_SHAPE', 'window'), ['window', 'legacy'], true) ? env('WEEKDAY_SHAPE', 'window') : 'window',

    'clip_mode' => in_array(env('CLIP_MODE', 'weekday'), ['weekday', 'legacy'], true) ? env('CLIP_MODE', 'weekday') : 'weekday',

    'safety_stock' => filter_var(env('SAFETY_STOCK', true), FILTER_VALIDATE_BOOLEAN),

    'lead_time_days' => max(0, min(7, (int) env('LEAD_TIME_DAYS', 0))),

    'service_z' => ['A' => 1.65, 'B' => 1.28, 'C' => 0.84, 'default' => 0.84],

    'max_overshoot' => (float) env('MAX_OVERSHOOT', 1.5),

    /*
    |--------------------------------------------------------------------------
    | Forecast data hygiene (ForecastService)
    |--------------------------------------------------------------------------
    |
    | refund_netting  'same_day' (default): a refund is netted only against sales of the SAME day of the same item,
    |                 each day's net is clamped at 0, so a refund that returns goods sold on an earlier day never
    |                 pushes the day, the stock estimate or the ABC revenue negative. Refund
    |                 lines use abs(qty). 'legacy': net = sales - refunds on the day the refund arrives.
    | feed_aware_censoring  true (default): a shop-day whose feed was silent (sale receipts < feed_silence_min_ratio x the
    |                 usual weekday median, usual >= feed_silence_min_median, median over the last 28 days, at least
    |                 feed_silence_min_weekdays comparable weekdays) is MISSING, not zero demand: left out of every mean
    |                 like a stock-out day (row key `silent_days`). More than 25 % of the window silent lowers the
    |                 confidence one step (never below low). false = old behaviour.
    |
    */

    'refund_netting' => in_array(env('REFUND_NETTING', 'same_day'), ['same_day', 'legacy'], true) ? env('REFUND_NETTING', 'same_day') : 'same_day',

    'feed_aware_censoring' => filter_var(env('FEED_AWARE_CENSORING', true), FILTER_VALIDATE_BOOLEAN),

    'feed_silence_min_ratio' => (float) env('FEED_SILENCE_MIN_RATIO', 0.2),

    'feed_silence_min_median' => (int) env('FEED_SILENCE_MIN_MEDIAN', 20),

    'feed_silence_min_weekdays' => (int) env('FEED_SILENCE_MIN_WEEKDAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Out of stock (main warehouse)
    |--------------------------------------------------------------------------
    |
    | warehouse_sync_min_coverage  items reported by the latest warehouse sync / items reported by the
    |                              previous one; below it the sync looks incomplete (partial) and the
    |                              list is only a warning.
    | warehouse_history_days       only items with a positive warehouse quantity within this many days
    |                              of the latest warehouse sync are considered.
    |
    */

    'warehouse_sync_min_coverage' => (float) env('WAREHOUSE_SYNC_MIN_COVERAGE', 0.7),

    'warehouse_history_days' => (int) env('WAREHOUSE_HISTORY_DAYS', 60),

    /*
    |--------------------------------------------------------------------------
    | Orders in transit, receipt and reminders
    |--------------------------------------------------------------------------
    |
    | in_transit_max_days      a confirmed, not yet received order counts as in transit (shop) / claimed
    |                          (warehouse) only this many days after its confirmation. Delivery is normally
    |                          the same day, so a forgotten order stops distorting the forecast after it.
    | in_transit_grace_hours   orders:expire-transit flags a confirmed order as expired this long after the window.
    | delivery_deadline        time of day (app timezone) by which a confirmed order should be received; an order
    |                          confirmed after it is due the next day.
    | reminder_hours           hours after a draft was created: reminder 1, reminder 2 (shop operators),
    |                          escalation to the admins.
    | reminder_max_age_hours   drafts older than this get no reminders (old forgotten drafts stay quiet).
    | admin_reminder_time      the morning (HH:MM) after the delivery day when the admins hear about a not received order.
    | stockout_digest_time     daily stock-out digest (HH:MM) for the shop operators and the admins.
    |
    */

    'in_transit_max_days' => max(1, (int) env('IN_TRANSIT_MAX_DAYS', 1)),

    'in_transit_grace_hours' => (int) env('IN_TRANSIT_GRACE_HOURS', 12),

    'delivery_deadline' => env('ORDER_DELIVERY_DEADLINE', '20:00'),

    'reminder_hours' => [2, 4, 6],

    'reminder_max_age_hours' => (int) env('ORDER_REMINDER_MAX_AGE_HOURS', 48),

    'admin_reminder_time' => env('ORDER_ADMIN_REMINDER_TIME', '09:00'),

    'stockout_digest_time' => env('STOCKOUT_DIGEST_TIME', '08:30'),

    /*
    |--------------------------------------------------------------------------
    | Order exceptions (admin approval before an order goes to 1C)
    |--------------------------------------------------------------------------
    |
    | order_exceptions             master switch (env ORDER_EXCEPTIONS=false turns the whole check off: every
    |                              confirm goes straight to 1C as before).
    | order_exception_max_multiple  a line above this x the item's max rule of the shop (rule needed).
    | order_exception_over_suggested  a system line above this x its suggested quantity.
    | order_exception_under_suggested a system line of an ABC class A item below this x its suggested quantity.
    | order_exception_total_multiple  order total pieces above this x the shop's average confirmed order total ...
    | order_exception_history_days     ... of the last this many days, ...
    | order_exception_min_orders       ... and only with at least this many earlier orders.
    | order_exception_max_qty      a line quantity above this is not a sane number.
    | Every rule is skipped silently when the data it needs does not exist.
    |
    */

    'order_exceptions' => filter_var(env('ORDER_EXCEPTIONS', true), FILTER_VALIDATE_BOOLEAN),

    'order_exception_max_multiple' => (float) env('ORDER_EXCEPTION_MAX_MULTIPLE', 3),

    'order_exception_over_suggested' => (float) env('ORDER_EXCEPTION_OVER_SUGGESTED', 2),

    'order_exception_under_suggested' => (float) env('ORDER_EXCEPTION_UNDER_SUGGESTED', 0.3),

    'order_exception_total_multiple' => (float) env('ORDER_EXCEPTION_TOTAL_MULTIPLE', 1.5),

    'order_exception_history_days' => (int) env('ORDER_EXCEPTION_HISTORY_DAYS', 28),

    'order_exception_min_orders' => (int) env('ORDER_EXCEPTION_MIN_ORDERS', 3),

    'order_exception_max_qty' => (float) env('ORDER_EXCEPTION_MAX_QTY', 100000),

    // How many error rows of the 1C items sync log (item_sync_errors) are kept, newest first.
    'item_sync_keep_errors' => (int) env('ITEM_SYNC_KEEP_ERRORS', 1000),

];
