<?php

use App\Livewire\Analytics\Index as AnalyticsIndex;
use App\Livewire\ReceiptAnalytics\Index as ReceiptAnalyticsIndex;
use App\Livewire\Ordering\Index as OrderingIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Categories\Index as CategoriesIndex;
use App\Livewire\Dashboard;
use App\Livewire\Groups\Index as GroupsIndex;
use App\Livewire\Items\Index as ItemsIndex;
use App\Livewire\Items\Show as ItemsShow;
use App\Livewire\MonitorList;
use App\Livewire\MonitorScreen;
use App\Livewire\Pos\Index as PosIndex;
use App\Livewire\Prices\Index as PricesIndex;
use App\Livewire\Receipts\Index as ReceiptsIndex;
use App\Livewire\Receipts\Show as ReceiptsShow;
use App\Livewire\Settings\Mcp as McpSettingsPage;
use App\Livewire\Settings\Monitors as MonitorsSettings;
use App\Livewire\Settings\Warehouse as WarehouseSettings;
use App\Livewire\Shops\Index as ShopsIndex;
use App\Livewire\Shops\Show as ShopsShow;
use App\Livewire\Users\Index as UsersIndex;
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

// Public wall monitor for a TV that cannot log in: the secret token in the URL
// is the only credential (each monitor's link is managed in Settings > Monitors).
// Unknown, regenerated or disabled tokens answer 404; the component re-checks
// the token on every Livewire poll too.
Route::get('/monitor/{token}', MonitorScreen::class)
    ->middleware(['throttle:60,1', 'monitor.headers'])
    ->name('monitor.public');

Route::middleware('auth')->group(function () {
    // Roles: admin = everything, operator = everything except settings,
    // monitor = the monitor screen only (see App\Enums\UserRole).
    // /monitor lists the monitors the user may open (straight to it when there is just one).
    Route::middleware('role:admin,operator,monitor')->group(function () {
        Route::get('/monitor', MonitorList::class)->name('monitor');
        Route::get('/monitors/{monitor}', MonitorScreen::class)->name('monitors.show');
    });

    Route::middleware('role:admin,operator')->group(function () {
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
        Route::get('/receipt-analytics', ReceiptAnalyticsIndex::class)->name('receipt-analytics.index');
        Route::get('/ordering', OrderingIndex::class)->name('ordering.index');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/settings/mcp', McpSettingsPage::class)->name('settings.mcp');
        Route::get('/settings/monitors', MonitorsSettings::class)->name('settings.monitors');
        Route::redirect('/settings/monitor', '/settings/monitors')->name('settings.monitor');
        Route::get('/settings/warehouse', WarehouseSettings::class)->name('settings.warehouse');
        Route::get('/settings/users', UsersIndex::class)->name('users.index');
    });
});
