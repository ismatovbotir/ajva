<?php

namespace App\Livewire;

use App\Models\Monitor;
use App\Models\Stock;
use App\Services\OrderRecommendations;
use App\Services\ReceiptsMetrics;
use App\Services\SalesMetrics;
use App\Support\MonitorScope;
use App\Support\ProfitAccess;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Wall-display ("kiosk") screen of one Monitor. This component is the shared
 * shell (header strip, clock, live/stale pill, polling every 30 seconds); the
 * body is the monitor type's own view, fed by the type's data method - see
 * App\Enums\MonitorType (view() / dataMethod()).
 *
 * Two modes: public (no login, /monitor/{token}: the monitor's own shops and
 * show_profit switch) and signed-in (/monitors/{monitor}: the monitor's shops
 * intersected with the user's ShopAccess, profit per ProfitAccess).
 */
class MonitorScreen extends Component
{
    private const CACHE_TTL = 25;

    private const ALERTS_TTL = 300;

    private const TICKER_ROWS = 8;

    private const WAREHOUSE_TTL = 60;

    private const PICK_LIMIT = 30;

    private const SHOP_ROWS = 6;

    /** Set only on the public route. Locked, so the browser cannot change it; re-validated on every request. */
    #[Locked]
    public ?string $publicToken = null;

    /** Set only on the signed-in route. */
    #[Locked]
    public ?int $monitorId = null;

    public function mount(?string $token = null, ?Monitor $monitor = null): void
    {
        $this->publicToken = $token;
        $this->monitorId = $token === null ? $monitor?->id : null;

        $this->resolveMonitor();
    }

    /** Livewire polls skip the route middleware, so check again after every rehydration. */
    public function hydrate(): void
    {
        $this->resolveMonitor();
    }

    /**
     * Public: served only while the token belongs to an existing, enabled
     * monitor. Signed-in: needs a user, and a disabled monitor is admin-only.
     * Anything else is a plain 404, so an old, wrong or disabled link never
     * reveals whether it ever existed.
     */
    private function resolveMonitor(): Monitor
    {
        if ($this->publicToken !== null) {
            $monitor = Monitor::findPublic($this->publicToken);
            abort_if($monitor === null, 404);

            return $monitor;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $monitor = $this->monitorId === null ? null : Monitor::query()->find($this->monitorId);
        abort_if($monitor === null || (! $monitor->enabled && ! $user->isAdmin()), 404);

        return $monitor;
    }

    public function render()
    {
        $monitor = $this->resolveMonitor();
        $now = now();
        $public = $this->publicToken !== null;

        $scope = new MonitorScope(
            monitor: $monitor,
            // The monitor's selection, narrowed to the viewer's shops when signed in.
            shopIds: $public ? $monitor->shopIds() : ShopAccess::intersect($monitor->shopIds(), ShopAccess::ids()),
            // Public screen: the monitor's own switch. Signed-in screen: the user's profit permission.
            withProfit: $public ? $monitor->show_profit : ProfitAccess::allowed(),
            public: $public,
            now: $now,
        );

        $typeData = $this->{$monitor->type->dataMethod()}($scope);

        return view('livewire.monitor-screen', $typeData + [
            'monitor' => $monitor,
            'scope' => $scope,
            'typeView' => $monitor->type->view(),
            'public' => $public,
            'showProfit' => $scope->withProfit,
            'noShops' => $scope->shopIds === [],
            'noShopsAssigned' => ShopAccess::hasNone(),
            'hasOtherMonitors' => ! $public && Monitor::openableBy(auth()->user())->count() > 1,
            'renderedAt' => $now->timestamp,
            'timezone' => config('app.timezone'),
            'currentHour' => (int) $now->format('G'),
            'dateLabel' => $now->format('d.m.Y'),
        ])->layout('components.layouts.monitor', ['title' => $monitor->name, 'public' => $public]);
    }

    /**
     * Executive type: the whole current day. Returns the SalesMetrics board
     * plus 'latest' (ticker) and 'alerts' (stock alerts shown in the header).
     *
     * @return array<string, mixed>
     */
    protected function executiveData(MonitorScope $scope): array
    {
        $now = $scope->now;
        $ids = $scope->shopIds;

        $data = Cache::remember(
            $scope->cacheKey('executive'),
            self::CACHE_TTL,
            fn () => app(SalesMetrics::class)->board($now->copy(), $ids, $scope->withProfit) + [
                'latest' => $this->latestReceipts($now->copy()->startOfDay(), $now, $ids),
            ],
        );

        return $data + [
            'alerts' => Cache::remember('dashboard.monitor.alerts.'.ShopAccess::scopeKey($ids), self::ALERTS_TTL, fn () => $this->stockAlerts($ids)),
        ];
    }

    /**
     * Receipts-analytics type: today's receipts of the monitor's shops against yesterday up to the same
     * time (see App\Services\ReceiptsMetrics for the metric dictionary). Profit only with $scope->withProfit.
     *
     * @return array<string, mixed>
     */
    protected function receiptsData(MonitorScope $scope): array
    {
        return [
            'r' => Cache::remember(
                $scope->cacheKey('receipts'),
                self::CACHE_TTL,
                fn () => app(ReceiptsMetrics::class)->board($scope->now->copy(), $scope->shopIds, $scope->withProfit),
            ),
        ];
    }

    /**
     * Warehouse type: order recommendations (rules + sales speed) for the monitor's shops, limited by the
     * main warehouse stock (see App\Services\OrderRecommendations). Cached ~60 s per monitor/scope; the key
     * also holds the chosen warehouse shop. Stock syncs from 1C do not bump sales.version, so they show up
     * after the TTL.
     *
     * @return array<string, mixed>
     */
    protected function warehouseData(MonitorScope $scope): array
    {
        $warehouseId = Warehouse::shopId();

        $rec = Cache::remember(
            $scope->cacheKey('warehouse.w'.($warehouseId ?? 0)),
            self::WAREHOUSE_TTL,
            function () use ($scope, $warehouseId) {
                $rec = app(OrderRecommendations::class)->build($scope->shopIds, $warehouseId, $scope->now->copy());

                // Keep the cached payload small: the screen shows few rows per shop and a capped pick list.
                $rec['pick_more'] = max(0, count($rec['pick']) - self::PICK_LIMIT);
                $rec['pick'] = array_slice($rec['pick'], 0, self::PICK_LIMIT);
                foreach ($rec['shops'] as &$shop) {
                    $shop['rows'] = array_slice($shop['rows'], 0, self::SHOP_ROWS);
                }
                unset($shop);

                return $rec;
            },
        );

        return ['rec' => $rec, 'warehouseName' => $warehouseId === null ? null : Warehouse::shop()?->name];
    }

    /**
     * Newest receipts of the day (refunds included, flagged), with their payment types.
     *
     * @return array<int, array<string, mixed>>
     */
    private function latestReceipts(Carbon $from, Carbon $to, ?array $ids = null): array
    {
        $rows = ShopAccess::restrictTo(DB::table('receipts'), 'receipts.shop_id', $ids)
            ->join('shops', 'shops.id', '=', 'receipts.shop_id')
            ->where('receipts.active', true)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->orderByDesc('receipts.created_at')
            ->orderByDesc('receipts.id')
            ->limit(self::TICKER_ROWS)
            ->get(['receipts.id', 'receipts.created_at', 'receipts.total', 'receipts.sell', 'shops.id as shop_id', 'shops.name as shop_name']);

        $payments = DB::table('receipt_payments')
            ->whereIn('receipt_id', $rows->pluck('id'))
            ->orderByDesc('value')
            ->get(['receipt_id', 'payment'])
            ->groupBy('receipt_id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'time' => Carbon::parse($r->created_at)->format('H:i:s'),
            'shop' => (string) $r->shop_name,
            'shop_id' => (int) $r->shop_id,
            'total' => number_format((float) $r->total, 0, '.', ' '),
            'refund' => ! $r->sell,
            'payment' => ($payments[$r->id] ?? collect())->pluck('payment')->unique()->implode(', '),
        ])->all();
    }

    /**
     * @return array{below_min: int, out_of_stock: int}
     */
    private function stockAlerts(?array $ids = null): array
    {
        $pairs = fn () => Stock::applyCurrent(ShopAccess::restrictTo(DB::table('stocks'), 'stocks.shop_id', $ids))->join('item_order_rules', function ($join) {
            $join->on('item_order_rules.item_id', '=', 'stocks.item_id')
                ->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
        });

        return [
            'below_min' => $pairs()->whereColumn('stocks.qty', '<=', 'item_order_rules.min')->count(),
            'out_of_stock' => $pairs()->where('stocks.qty', '<=', 0)->where('item_order_rules.min', '>', 0)->count(),
        ];
    }
}
