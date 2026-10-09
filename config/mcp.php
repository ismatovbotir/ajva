<?php

use App\Mcp\Tools;

return [

    /*
    |--------------------------------------------------------------------------
    | MCP bearer token
    |--------------------------------------------------------------------------
    |
    | Clients must send "Authorization: Bearer <token>" to POST /api/mcp. While
    | this is empty the endpoint rejects every request. Generate one with:
    |   php -r "echo bin2hex(random_bytes(24));"
    |
    */

    'token' => env('MCP_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
    | To add a tool: create a class implementing App\Mcp\Tool (extending
    | App\Mcp\BaseTool gives you date / int argument helpers) and list it
    | here. Tools must stay read-only.
    |
    */

    'tools' => [
        Tools\ListShops::class,
        Tools\SalesSummary::class,
        Tools\HourlySales::class,
        Tools\TopItems::class,
        Tools\StockLevels::class,
        Tools\GetReceipt::class,
        Tools\ListPriceTypes::class,
        Tools\ItemPrices::class,
        Tools\ReceiptAnalyticsReport::class,
    ],

];
