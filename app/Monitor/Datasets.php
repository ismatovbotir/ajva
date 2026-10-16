<?php

namespace App\Monitor;

use App\Models\Shop;
use App\Models\Stock;
use App\Services\AssortmentAnalysis;
use App\Services\ForecastService;
use App\Services\OrderRecommendations;
use App\Services\ReceiptsMetrics;
use App\Services\SalesMetrics;
use App\Services\UnsoldStock;
use App\Services\WarehouseOutOfStock;
use App\Support\MonitorScope;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The shared data pass of one monitor refresh. Every dataset (receipts, hourly, trend, top_items, profit,
 * inventory, sync) is built at most once per refresh and only when a widget of the layout asks for it:
 * a layout without inventory widgets never touches ForecastService, a layout without profit widgets never
 * runs a cost query, and profit is never built unless the scope allows it. Datasets are cached by the
 * MonitorScope cache key (scope, day, profit variant, sales.version) + layout_version + dataset name,
 * for the shortest ttl of the widgets using them. Widgets then select slices (see WidgetPayloads).
 */
class Datasets
{
    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    /** @param  array<string, int>  $ttls  dataset => seconds (the shortest ttl of its widgets) */
    public function __construct(private readonly MonitorScope $scope, private readonly array $ttls) {}

    /** The names of the datasets that were actually built during this refresh (for tests and diagnostics). */
    public function built(): array
    {
        return array_keys($this->memo);
    }

    /** @return array<string, mixed> */
    public function get(string $name): array
    {
        if (! isset($this->memo[$name])) {
            $this->memo[$name] = Cache::remember(
                $this->scope->cacheKey('custom.'.$name.'.l'.$this->scope->monitor->layout_version.'.w'.(Warehouse::shopId() ?? 0)),
                $this->ttls[$name] ?? 30,
                fn () => ['generated_at' => now()->timestamp] + $this->build($name),
            );
        }

        return $this->memo[$name];
    }

    /**
     * Shops for the sales widgets: the monitor's shops without the main warehouse (it sells nothing, it supplies).
     *
     * @return array<int, int>|null null = every shop
     */
    public function salesShopIds(): ?array
    {
        $ids = $this->scope->shopIds;
        $warehouse = Warehouse::shopId();
        if ($warehouse === null) {
            return $ids;
        }

        if ($ids === null) {
            return Shop::query()->where('id', '!=', $warehouse)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return array_values(array_filter($ids, fn ($id) => $id !== $warehouse));
    }

    /** @return array<string, mixed> */
    private function build(string $name): array
    {
        return match ($name) {
            'receipts' => $this->receipts(),
            'hourly' => $this->hourly(),
            'trend' => $this->trend(),
            'top_items' => $this->topItems(),
            'profit' => $this->profit(),
            'inventory' => $this->inventory(),
            'sync' => $this->sync(),
            'assortment' => $this->assortment(),
            'unsold' => $this->unsold(),
            'wh_oos' => $this->warehouseOutOfStock(),
            'customers' => $this->customers(),
            default => [],
        };
    }

    private function receipts(): array
    {
        $ids = $this->salesShopIds();
        $now = $this->scope->now->copy();
        $weekStart = SalesMetrics::windows($now)['week'][0];

        return [
            // Profit is never part of this dataset: it has its own, gated one.
            'board' => app(ReceiptsMetrics::class)->board($now, $ids, false),
            // A week-ago day without any receipt means the baseline B is missing, not "zero sales".
            'week_day_has_data' => ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $ids)
                ->whereBetween('created_at', [$weekStart, $weekStart->copy()->endOfDay()])->exists(),
        ];
    }

    private function hourly(): array
    {
        $now = $this->scope->now->copy();
        $todayStart = $now->copy()->startOfDay();

        return [
            'series' => app(SalesMetrics::class)->hourly($todayStart, $todayStart->copy()->subDay(), [], [], $this->salesShopIds()),
            'current_hour' => (int) $now->format('G'),
        ];
    }

    private function trend(): array
    {
        return ['days' => app(SalesMetrics::class)->trend($this->scope->now->copy()->startOfDay(), $this->salesShopIds(), false, 8)];
    }

    /**
     * Top items by quantity today with the same item sold up to the same time yesterday and one week ago (NEW).
     * One grouped pass over the three windows; baseline receipt counts gate the deltas.
     */
    private function topItems(): array
    {
        $ids = $this->salesShopIds();
        $w = SalesMetrics::windows($this->scope->now->copy());
        [$t0] = $w['today'];
        [$y0, $y1] = $w['yesterday'];
        [$w0, $w1] = $w['week'];

        $rows = ShopAccess::restrictTo(
            DB::table('receipt_items as ri')->join('receipts', 'receipts.id', '=', 'ri.receipt_id'),
            'receipts.shop_id',
            $ids
        )
            ->where('receipts.active', true)
            ->where('receipts.sell', true)
            ->where('ri.storno', false)
            ->where(fn ($q) => $q
                ->whereBetween('receipts.created_at', $w['today'])
                ->orWhereBetween('receipts.created_at', $w['yesterday'])
                ->orWhereBetween('receipts.created_at', $w['week']))
            ->groupBy('ri.item_id')
            ->selectRaw(
                'ri.item_id AS item_id, '
                .'SUM(CASE WHEN receipts.created_at >= ? THEN ri.qty ELSE 0 END) AS q_now, '
                .'SUM(CASE WHEN receipts.created_at >= ? THEN ri.total ELSE 0 END) AS s_now, '
                .'SUM(CASE WHEN receipts.created_at >= ? AND receipts.created_at <= ? THEN ri.qty ELSE 0 END) AS q_prev, '
                .'SUM(CASE WHEN receipts.created_at >= ? AND receipts.created_at <= ? THEN ri.qty ELSE 0 END) AS q_week',
                [$t0, $t0, $y0, $y1, $w0, $w1]
            )
            ->havingRaw('SUM(CASE WHEN receipts.created_at >= ? THEN ri.qty ELSE 0 END) > 0', [$t0])
            ->orderByDesc('q_now')
            ->orderByDesc('s_now')
            ->limit(WidgetCatalog::TOP_N_MAX)
            ->get();

        $names = DB::table('items')->whereIn('id', $rows->pluck('item_id'))->pluck('name', 'id');
        $sales = app(SalesMetrics::class);
        $count = fn (array $window) => array_sum(array_column($sales->shopTotals($window[0], $window[1], $ids), 'count'));

        return [
            'items' => $rows->map(fn ($r) => [
                'name' => (string) ($names[$r->item_id] ?? '#'.$r->item_id),
                'qty' => (float) $r->q_now,
                'sum' => (float) $r->s_now,
                'prev_qty' => (float) $r->q_prev,
                'week_qty' => (float) $r->q_week,
            ])->all(),
            'prev_base' => $count($w['yesterday']),
            'week_base' => $count($w['week']),
            'as_of' => $this->scope->now->format('H:i'),
        ];
    }

    /** Built only when the scope allows profit; otherwise never queried (profit widgets are absent from the payload). */
    private function profit(): array
    {
        if (! $this->scope->withProfit) {
            return [];
        }

        $ids = $this->salesShopIds();
        $sales = app(SalesMetrics::class);
        $w = SalesMetrics::windows($this->scope->now->copy());

        return [
            'today' => $sales->profitByShop($w['today'][0], $w['today'][1], $ids),
            'prev' => $sales->profitByShop($w['yesterday'][0], $w['yesterday'][1], $ids),
            'week' => $sales->profitByShop($w['week'][0], $w['week'][1], $ids),
            'shops' => ShopAccess::restrictTo(DB::table('shops'), 'id', $ids)->pluck('name', 'id')->all(),
            'as_of' => $this->scope->now->format('H:i'),
        ];
    }

    /**
     * The Ordering forecast (ForecastService, each shop's order rule horizon) planned against the main
     * warehouse. Shops in view without the warehouse; the stock is the last 1C snapshot (stock_date).
     */
    private function inventory(): array
    {
        $warehouseId = Warehouse::shopId();
        $ids = $this->salesShopIds();

        $forecast = app(ForecastService::class)->build($ids, $warehouseId === null ? [] : [$warehouseId], $this->scope->now->copy());
        $plan = app(OrderRecommendations::class)->plan($forecast, null, $warehouseId);
        $shopIds = array_column($forecast['shops'], 'id');

        $ruled = $shopIds === [] ? 0 : (int) Stock::applyCurrent(DB::table('stocks'))
            ->join('item_order_rules as r', function ($join) {
                $join->on('r.item_id', '=', 'stocks.item_id')->on('r.shop_id', '=', 'stocks.shop_id');
            })
            ->whereIn('stocks.shop_id', $shopIds)
            ->where('r.max', '>', 0)
            ->count();

        return [
            'plan' => $plan,
            'ruled' => $ruled,
            'warehouse_name' => $warehouseId === null ? null : Warehouse::shop()?->name,
            'stock_as_of' => $this->lastStockDate($shopIds),
        ];
    }

    /**
     * The shops of the sales widgets as a concrete list (the assortment services need ids, not "all").
     *
     * @return array<int, int>
     */
    private function shopList(): array
    {
        return $this->salesShopIds() ?? Shop::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** ABC x XYZ over the last 12 whole weeks (AssortmentAnalysis); only the small figures the widgets draw are kept. */
    private function assortment(): array
    {
        $to = $this->scope->now->copy()->subDay();
        $r = app(AssortmentAnalysis::class)->build($to->copy()->subDays(83), $to, $this->shopList(), false);

        return [
            'items' => $r['kpi']['items'],
            'weeks' => $r['window']['weeks'],
            'xyz' => $r['window']['xyz'],
            'abc' => $r['abc_summary'],
            'matrix' => $r['matrix'],
            'a_at_risk' => $r['kpi']['a_at_risk'],
            'stock_as_of' => $r['kpi']['stock_as_of'],
        ];
    }

    /** Stock without sales over the last 60 days (UnsoldStock), figures and groups only. */
    private function unsold(): array
    {
        $to = $this->scope->now->copy()->subDay();
        $r = app(UnsoldStock::class)->build($to->copy()->subDays(59), $to, $this->shopList(), false);

        return ['days' => $r['window']['days'], 'kpi' => $r['kpi'], 'groups' => $r['groups']];
    }

    /** Main warehouse out-of-stock figures (WarehouseOutOfStock): state, sync dates and the KPI block. */
    private function warehouseOutOfStock(): array
    {
        $d = app(WarehouseOutOfStock::class)->data($this->scope->now->copy());

        return ['state' => $d['state'], 'ref' => $d['ref'], 'chain' => $d['chain'], 'partial' => $d['partial'], 'kpi' => $d['kpi'], 'warehouse' => $d['warehouse']];
    }

    /** Today's sale receipts and how many of them carry a customer / loyalty card (the aos JSON column, as in ReceiptAnalytics). */
    private function customers(): array
    {
        $from = $this->scope->now->copy()->startOfDay();
        $row = ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $this->salesShopIds())
            ->where('active', true)->where('sell', true)
            ->whereBetween('created_at', [$from, $this->scope->now->copy()])
            ->selectRaw("COUNT(*) AS n, SUM(CASE WHEN (aos IS NOT NULL AND aos NOT IN ('[]', '{}', 'null', '')) THEN 1 ELSE 0 END) AS c")
            ->first();

        return ['all' => (int) ($row->n ?? 0), 'with' => (int) ($row->c ?? 0)];
    }

    private function sync(): array
    {
        $ids = $this->salesShopIds();
        $date = $this->lastStockDate($ids === null ? null : $ids);

        // stock_date is a day, not a time: count from the end of that day, i.e. the latest moment the sync could have run.
        $hours = $date === null ? null : max(0.0, Carbon::parse($date)->endOfDay()->diffInMinutes($this->scope->now->copy(), false) / 60);

        return ['stock_date' => $date, 'hours' => $hours];
    }

    /** @param  array<int, int>|null  $shopIds */
    private function lastStockDate(?array $shopIds): ?string
    {
        $max = ShopAccess::restrictTo(Stock::applyCurrent(DB::table('stocks')), 'stocks.shop_id', $shopIds)->max('stocks.stock_date');

        return $max === null ? null : substr((string) $max, 0, 10);
    }
}
