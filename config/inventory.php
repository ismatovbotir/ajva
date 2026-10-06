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

];
