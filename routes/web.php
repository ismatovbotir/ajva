<?php

use App\Livewire\Analytics\Index as AnalyticsIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Categories\Index as CategoriesIndex;
use App\Livewire\Dashboard;
use App\Livewire\Groups\Index as GroupsIndex;
use App\Livewire\Items\Index as ItemsIndex;
use App\Livewire\Items\Show as ItemsShow;
use App\Livewire\Pos\Index as PosIndex;
use App\Livewire\Prices\Index as PricesIndex;
use App\Livewire\Receipts\Index as ReceiptsIndex;
use App\Livewire\Receipts\Show as ReceiptsShow;
use App\Livewire\Settings\Mcp as McpSettingsPage;
use App\Livewire\Shops\Index as ShopsIndex;
use App\Livewire\Shops\Show as ShopsShow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/login', Login::class)->middleware('guest')->name('login');

Route::post('/logout', function () {
    Auth::logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/shops', ShopsIndex::class)->name('shops.index');
    Route::get('/shops/{shop}', ShopsShow::class)->name('shops.show');
    Route::get('/pos', PosIndex::class)->name('pos.index');
    Route::get('/groups', GroupsIndex::class)->name('groups.index');
    Route::get('/categories', CategoriesIndex::class)->name('categories.index');
    Route::get('/prices', PricesIndex::class)->name('prices.index');
    Route::get('/items', ItemsIndex::class)->name('items.index');
    Route::get('/items/{item}', ItemsShow::class)->name('items.show');
    Route::get('/receipts', ReceiptsIndex::class)->name('receipts.index');
    Route::get('/receipts/{receipt}', ReceiptsShow::class)->name('receipts.show');
    Route::get('/analytics', AnalyticsIndex::class)->name('analytics.index');
    Route::get('/settings/mcp', McpSettingsPage::class)->name('settings.mcp');
});
