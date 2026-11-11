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
        Tools\AssortmentAnalysisReport::class,
        Tools\UnsoldStockReport::class,
        Tools\ListOrders::class,
        Tools\GetOrder::class,
        Tools\ReplenishmentForecast::class,
        Tools\OrderRulesReport::class,
        Tools\ListMonitors::class,
        Tools\WarehouseOutOfStock::class,
        Tools\ListTelegramMessages::class,
        Tools\GetTelegramMessage::class,
        Tools\TelegramInboxAnalysis::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram keyword stop words
    |--------------------------------------------------------------------------
    |
    | Words (3+ letters; shorter ones are skipped anyway) that telegram_inbox_analysis leaves out of its
    | keyword counts. Minimal Uzbek / Russian / English list; extend as needed.
    |
    */

    'telegram_stop_words' => [
        // English
        'the', 'and', 'for', 'you', 'are', 'was', 'not', 'with', 'this', 'that', 'have', 'from', 'what', 'how', 'can', 'please', 'thanks', 'thank', 'hello',
        // Uzbek
        'bor', 'yoq', 'yo\'q', 'emas', 'men', 'sen', 'biz', 'siz', 'bu', 'va', 'bilan', 'uchun', 'qanday', 'nima', 'iltimos', 'rahmat', 'salom', 'assalomu', 'alaykum', 'kerak', 'ham', 'lekin', 'yana', 'endi', 'bir',
        // Russian
        'это', 'как', 'что', 'для', 'при', 'или', 'все', 'вот', 'мне', 'нет', 'так', 'уже', 'еще', 'есть', 'был', 'была', 'будет', 'спасибо', 'пожалуйста', 'привет', 'здравствуйте', 'добрый', 'день',
    ],

];
