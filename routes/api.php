<?php

use App\Http\Controllers\Api\LocalItemsController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\ReceiptController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('local.only')->post('/items', [LocalItemsController::class, 'store']);

Route::middleware('pos.token')->post('/receipts', [ReceiptController::class, 'store']);

// Read-only MCP server (JSON-RPC over HTTP) for AI clients; tools live in config/mcp.php.
Route::middleware(['mcp.token', 'throttle:60,1'])->post('/mcp', McpController::class);
