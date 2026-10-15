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

];
