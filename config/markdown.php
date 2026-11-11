<?php

/*
|--------------------------------------------------------------------------
| Sales growth > Markdown and promo (App\Services\Markdowns)
|--------------------------------------------------------------------------
|
| Every threshold of the page lives here (also printed in the page's "How this is calculated" block).
| Everything is a SIGNAL TO REVIEW and a suggested start to test and adjust, never an instruction.
| There is no shelf-life data in the system: candidates are slow stock, not expiry risk.
|
*/

return [

    /** Period of sales evidence: default length (whole days ending yesterday), shortest and longest allowed. */
    'default_days' => 60,
    'min_days' => 7,
    'max_days' => 92,

    /** How long a built report is cached (every Generate press builds a fresh one). */
    'cache_seconds' => 600,

    'candidates' => [
        /** A shop needs this many days of data (first receipt or first stock row) before its pairs are judged. */
        'min_shop_history_days' => 60,
        /** An item first seen (first receipt line or first stock row) fewer days ago is new, not slow. */
        'min_item_age_days' => 28,
        /** Candidate when on hand / speed is above this many days of cover. */
        'cover_days' => 90,
        /** Candidate when on hand is above this multiple of the shop's max rule (max > 0). */
        'over_max_multiple' => 3,
        /** Cover above this is shown as ">365 d". */
        'cover_cap_days' => 365,
        /** Small-stock guard: a pair needs at least this many units AND this much value at the sell price. */
        'min_units' => 3,
        'min_value' => 20000,
        /** The row cap of the lists (totals stay exact). */
        'max_rows' => 5000,
        /** Items of these groups (group ids) are short-life / fresh: "expiry: check by hand", no depth ladder. */
        'fresh_group_ids' => [],
        /** A pair with last year's sales in the same period of at least this many units gets the seasonal hint. */
        'seasonal_min_units' => 3,
        /** Transfer first: another shop sells the item with less than this many days of cover. */
        'transfer_cover_days' => 30,
        /** Horizon (days) of the order quantity taken from ForecastService for the transfer hint. */
        'transfer_horizon_days' => 3,
    ],

    /**
     * Suggested depth ladder (per cent), a suggested start to test and adjust. The DEEPER rung of the cover-based
     * and the last-sale-age-based rung is suggested.
     */
    'ladder' => [
        /** Cover rungs: from this many days of cover. */
        'cover' => [
            ['from' => 90, 'depth' => 10],
            ['from' => 180, 'depth' => 20],
        ],
        /** No sale in the period. */
        'no_sale_depth' => 20,
        /** Over the max rule only (sales exist, cover below the cover threshold). */
        'over_max_depth' => 10,
        /** No sale for this many days (never sold: the item must be at least this old). */
        'old_sale_days' => 120,
        'old_sale_depth' => 30,
    ],

    /** Discount effectiveness (receipt lines only). */
    'effectiveness' => [
        /** A line is discounted when its discount (sum - totalSum) is above this share of the line sum. */
        'line_min_depth' => 0.05,
        /** A day is a discounted day when at least this share of the item's units sold carries a line discount. */
        'day_min_share' => 0.5,
        /** ... and the depths of its discounted lines are within this many points of each other (0.10 = 10 points). */
        'depth_spread' => 0.10,
        /** A full-price day: at most this share of the units discounted (days in between are left out of both groups). */
        'full_price_max_share' => 0.1,
        /** Days needed: discounted and full-price (in stock, not silent). */
        'min_discounted_days' => 3,
        'min_full_price_days' => 5,
        /** Cannibalised: lift below this AND a give-away above the amount below. */
        'cannibalised_lift' => 1.2,
        'cannibalised_min_giveaway' => 50000,
        /** Rarely sold at full price: more than this share of the period's units sold discounted. */
        'rarely_full_share' => 0.8,
        /** Episode speeds: days before the start and after the end. */
        'before_days' => 14,
        'after_days' => 7,
        'episodes_per_pair' => 3,
        /** Pairs kept for the heavy passes (largest give-away first) and rows shown. */
        'max_pairs' => 2000,
        'max_rows' => 500,
    ],

];
