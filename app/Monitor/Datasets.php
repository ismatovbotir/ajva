<?php

namespace App\Monitor;

use App\Models\Shop;
use App\Models\Stock;
use App\Services\AssortmentAnalysis;
use App\Services\DataHealth;
use App\Services\ForecastService;
use App\Services\OrderRecommendations;
use App\Services\ReceiptsMetrics;
use App\Services\SalesMetrics;
use App\Services\SecurityWarnings;
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
            'security_week' => $this->securityWeek(),
            'security_today' => $this->securityToday(),
            'data_health' => $this->dataHealth(),
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

        // Explicit zero: only pairs the latest sync reported count as tracked (not-reported pairs are unknown).
        $ruled = $shopIds === [] ? 0 : (int) Stock::applyReported(Stock::applyCurrent(DB::table('stocks')))
            ->join('item_order_rules as r', function ($join) {
                $join->on('r.item_id', '=', 'stocks.item_id')->on('r.shop_id', '=', 'stocks.shop_id');
            })
            ->whereIn('stocks.shop_id', $shopIds)
            ->where('r.max', '>', 0)
            ->count();

        return [
            'plan' => $plan,
            'ruled' => $ruled,
            'no_data' => (int) ($forecast['no_data']['count'] ?? 0),
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

    private const SEVERITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2];

    /**
     * Security, rolling 7 days (SecurityWarnings, admin viewers only; empty for anyone else so nothing is ever queried):
     * severity counts, chain counts now and for the previous 7 days cut at the same time, and the flagged CASHIER rows of
     * the refund and deep-discount checks. Cashier names live only in this dataset, which only an admin screen builds.
     */
    private function securityWeek(): array
    {
        if (! $this->scope->viewerIsAdmin()) {
            return [];
        }

        $now = $this->scope->now->copy();
        $from = $now->copy()->subDays(6)->startOfDay();
        $ids = $this->shopList();
        $service = app(SecurityWarnings::class);

        $report = $service->build($from, $now, $ids, $now);
        $current = $service->figures($from, $now, $ids);
        $previous = $service->figures($from->copy()->subDays(7), $now->copy()->subDays(7), $ids);

        $minReceipts = (int) config('security.min_receipts');
        $minBase = (int) config('security.min_baseline_receipts');
        $judgeable = count(array_filter($current['cashiers'], fn (array $c) => $c['all_count'] >= $minReceipts && $current['chain']['all_count'] - $c['all_count'] >= $minBase));

        $pick = fn (string $type) => array_values(array_map(
            fn (array $w) => ['shop' => (string) $w['shop'], 'cashier' => (string) ($w['cashier'] ?? ''), 'severity' => $w['severity'], 'value' => (float) $w['value'], 'baseline' => (float) ($w['baseline'] ?? 0), 'sample' => (int) $w['sample']],
            array_filter($report['warnings'], fn (array $w) => $w['type'] === $type && $w['subject'] === 'cashier')
        ));
        $chain = fn (array $c) => ['all' => (int) $c['all_count'], 'cancelled' => (int) $c['cancelled_count'], 'empty' => (int) $c['empty_count']];

        return [
            'as_of' => $now->format('H:i'),
            'receipts' => (int) $report['stamp']['receipts'],
            'kpi' => $report['kpi'],
            'chain' => $chain($current['chain']),
            'prev_chain' => $chain($previous['chain']),
            'judgeable' => $judgeable,
            'refund' => $pick('refund_abuse'),
            'discount' => $pick('discount_deep'),
        ];
    }

    /**
     * Security, today so far (admin viewers only): the per-day checks of SecurityWarnings over today's receipts, reduced to
     * counts and a few shop rows. No cashier name leaves this method.
     */
    private function securityToday(): array
    {
        if (! $this->scope->viewerIsAdmin()) {
            return [];
        }

        $now = $this->scope->now->copy();
        $start = $now->copy()->startOfDay();
        $ids = $this->shopList();
        $service = app(SecurityWarnings::class);

        $perShop = $ids === [] ? collect() : DB::table('receipts')->whereIn('shop_id', $ids)
            ->where('active', true)->where('sell', true)->whereBetween('created_at', [$start, $now])
            ->groupBy('shop_id')->selectRaw('shop_id, COUNT(*) as n')->pluck('n', 'shop_id');

        $worst = function (array $rows): string {
            $rank = array_map(fn (array $r) => self::SEVERITY_RANK[$r['severity']], $rows);

            return $rank === [] ? 'none' : array_search(min($rank), self::SEVERITY_RANK, true);
        };
        $sum = fn (array $rows) => (int) array_sum(array_column($rows, 'value'));

        $off = $ids === [] ? [] : $service->offHours($start, $now, $ids);
        $silent = $ids === [] ? [] : $service->silentShops($start, $now, $ids, $now);
        $gaps = $ids === [] ? [] : $service->numberGaps($start, $now, $ids);
        $dups = $ids === [] ? [] : $service->duplicates($start, $now, $ids);
        $data = $ids === [] ? [] : $service->dataIntegrity($start, $now, $ids, $now);
        $baskets = $ids === [] ? [] : $service->unusualBaskets($start, $now, $ids);
        usort($baskets, fn (array $a, array $b) => [self::SEVERITY_RANK[$a['severity']], -$a['value']] <=> [self::SEVERITY_RANK[$b['severity']], -$b['value']]);

        return [
            'as_of' => $now->format('H:i'),
            'sale_receipts' => (int) $perShop->sum(),
            'basket_shops' => $perShop->filter(fn ($n) => $n >= (int) config('security.basket_min_receipts'))->count(),
            'normal_shops' => $ids === [] ? 0 : $service->normalHoursShops($now, $ids),
            'off' => ['count' => $sum($off), 'severity' => $worst($off)],
            'silent' => array_map(fn (array $w) => ['shop' => (string) $w['shop'], 'hours' => (int) $w['value'], 'expected' => (int) $w['baseline'], 'severity' => $w['severity']], $silent),
            'gaps' => ['missing' => $sum($gaps), 'tills' => count($gaps), 'severity' => $worst($gaps)],
            'dups' => ['count' => count($dups), 'severity' => $worst($dups)],
            'integrity' => ['count' => $sum($data), 'shops' => count(array_unique(array_column($data, 'shop_id'))), 'severity' => $worst($data)],
            'baskets' => array_map(fn (array $w) => ['shop' => (string) $w['shop'], 'total' => (float) $w['value'], 'cut' => (float) $w['baseline'], 'severity' => $w['severity']], array_slice($baskets, 0, WidgetCatalog::TOP_N_MAX)),
        ];
    }

    /** Receipt feed health (DataHealth): silent shops and the rejected receipts of the last 24 hours. No cashier or cost data. */
    private function dataHealth(): array
    {
        $now = $this->scope->now->copy();
        $r = app(DataHealth::class)->build($this->salesShopIds(), $now);
        $bad = array_filter($r['shops'], fn (array $s) => in_array($s['status'], ['bad', 'warn'], true));

        return [
            'as_of' => $now->format('H:i'),
            'shops' => count($r['shops']),
            'judged' => count(array_filter($r['shops'], fn (array $s) => $s['status'] !== 'na')),
            'problems' => array_values(array_map(fn (array $s) => ['shop' => $s['name'], 'last24' => $s['last24'], 'usual' => $s['usual'], 'status' => $s['status']], $bad)),
            'rejected' => ['total' => $r['rejections']['total24'], 'spike' => $r['rejections']['spike'], 'by_reason' => $r['rejections']['by_reason']],
        ];
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
