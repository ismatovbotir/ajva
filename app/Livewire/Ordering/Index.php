<?php

namespace App\Livewire\Ordering;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderRule;
use App\Services\ForecastService;
use App\Services\OrderRecommendations;
use App\Services\ReplenishmentOrders;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ordering report (ADMIN only): a 1-7 day sales forecast (App\Services\ForecastService) turned into an order
 * list per shop and a warehouse pick list (App\Services\OrderRecommendations::plan). The admin generates an
 * editable DRAFT per shop ("Prepare order", or "Prepare orders for all shops" = one separate draft per shop,
 * App\Services\ReplenishmentOrders); the shop's responsible person edits and confirms it under Orders.
 *
 * The heavy forecast is cached once per (viewer scope, sales version, warehouse, orders version, Refresh);
 * it already contains order[1..7] for every pair, so switching the horizon only re-plans (cheap) and caches
 * the plan per horizon.
 *
 * View data (livewire.ordering.index):
 *  noShops (bool)            no shops to show
 *  horizon (int 1-7), horizons (int[])
 *  tab ('shops'|'pick')
 *  generated_at (string|null)
 *  warehouseConfigured (bool), warehouseName (string|null)
 *  kpi {shops, lines, items, out, critical, soon, low, short_items, short_lines, starved}
 *  shops: list (by name) of {id, name, count, out, short, counts{out,critical,soon,low}, rows}
 *  active: the selected entry of shops (or null)
 *  drafts: [shop id => order id] open drafts (link to Orders)
 *  rows[] (one order line):
 *    item_id, item, group, shop_id, abc (A|B|C), xyz (X|Y|Z|null), stock, stock_date, estimated, in_transit,
 *    min, max, speed (per day, null = rules only), cover (days, null), stockout_in (0-7|null), stockout_date
 *    (Y-m-d|null), forecast (F[H]), need (= order[H]), send (warehouse-limited, = need without a warehouse),
 *    short, starved, confidence (none|low|ok|high), rules_only, clipped, stale, censored_days,
 *    level (out|critical|soon|low|ok|over), urgency (out|critical|soon|low), tier (0-3), reason, piece
 *  pick[]: {item_id, item, need, send, available (null without warehouse), short, short_pct, urgency, cover,
 *    starved, shops[]: {shop_id, shop, need, send, short, urgency, starved}}
 *  notice: null or {created: [{shop, order_id, lines}], updated: [{shop, order_id, lines (added)}],
 *    skipped: [{shop, reason: empty|forbidden}]}
 */
#[Layout('components.layouts.app', ['title' => 'Ordering'])]
class Index extends Component
{
    private const ORDERS_VERSION_KEY = 'ordering.orders.version';

    /** Changes on every Refresh press; part of the cache key. */
    public string $runKey = 'initial';

    /**
     * false until the admin presses Generate (or Refresh): opening the page calculates nothing, the forecast is heavy
     * and the admin may want to pick the cover days first.
     */
    public bool $generated = false;

    public int $horizon = 0;

    /**
     * false (default): every shop is planned with its own order-rule horizon (Settings > Order rules), the same
     * as the automatic draft. true: the admin picked a horizon with the selector and it applies to every shop.
     */
    public bool $custom = false;

    public ?int $shopId = null;

    public string $tab = 'shops';

    /** Item whose forecast chart is expanded in the selected shop's list (presentation only). */
    public ?int $openItem = null;

    /** @var array<string, mixed>|null */
    public ?array $notice = null;

    public function boot(): void
    {
        // Defense in depth on top of the role:admin route: every request (also Livewire actions) re-checks.
        Gate::authorize('generate', Order::class);
    }

    public function mount(): void
    {
        $this->horizon = ForecastService::horizon();
    }

    /** The selector was used: this horizon now applies to every shop. */
    public function setHorizon(int $horizon): void
    {
        $this->horizon = ForecastService::horizon($horizon);
        $this->custom = true;
    }

    public function updatedHorizon(): void
    {
        $this->custom = true;
    }

    /** Back to each shop's own horizon from its order rule. */
    public function useShopRules(): void
    {
        $this->custom = false;
        $this->horizon = ForecastService::horizon();
    }

    /** The Generate button (always shown): every press recalculates from fresh data (same work as refresh()). */
    public function generate(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->generated = true;
        $this->runKey = bin2hex(random_bytes(4));
        $this->notice = null;

        // Build now (the "Calculating" popup covers the wait) so the re-render only reads the cache.
        $this->horizon = ForecastService::horizon($this->horizon ?: null);
        if (! ShopAccess::hasNone()) {
            $this->forecast();
        }
    }

    public function selectShop(int $shopId): void
    {
        ShopAccess::authorize($shopId);

        $this->shopId = $shopId;
        $this->tab = 'shops';
        $this->openItem = null;
    }

    public function toggleItem(int $itemId): void
    {
        $this->openItem = $this->openItem === $itemId ? null : $itemId;
    }

    public function showTab(string $tab): void
    {
        $this->tab = $tab === 'pick' ? 'pick' : 'shops';
    }

    /** Saves the suggested list of ONE shop as its editable draft (adds new items to an existing draft). */
    public function prepareOrder(int $shopId): void
    {
        ShopAccess::authorize($shopId);
        if (! $this->generated) {
            return;
        }

        $this->notice = $this->prepare([$shopId]);
    }

    /** One separate draft per shop that has something to order. */
    public function prepareAllOrders(): void
    {
        if (! $this->generated) {
            return;
        }
        $this->notice = $this->prepare(null);
    }

    /**
     * @param  array<int, int>|null  $shopIds  null = every shop of the plan that has lines
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function prepare(?array $shopIds): array
    {
        $this->horizon = ForecastService::horizon($this->horizon ?: null);
        $plan = $this->plan();
        $shopIds ??= collect($plan['shops'])->where('count', '>', 0)->pluck('id')->all();

        $result = ['created' => [], 'updated' => [], 'skipped' => []];
        foreach ($shopIds as $id) {
            $one = app(ReplenishmentOrders::class)->prepare($plan, (int) $id, auth()->user());
            foreach ($result as $k => $list) {
                $result[$k] = array_merge($list, $one[$k]);
            }
        }

        return $result;
    }

    public function render()
    {
        $noShops = ShopAccess::hasNone();
        $this->horizon = ForecastService::horizon($this->horizon ?: null);

        $ready = ! $noShops && $this->generated;
        $plan = $ready ? $this->plan() : $this->emptyPlan();
        $shops = collect($plan['shops'])->sortBy('name')->values()->map(function (array $s) {
            $s['out'] = $s['counts']['out'];

            return $s;
        });

        // A forged/stale shop id falls back to the first shop that has something to order.
        if ($shops->isNotEmpty() && ! $shops->contains('id', $this->shopId)) {
            $this->shopId = ($shops->firstWhere('count', '>', 0) ?? $shops->first())['id'];
        }

        $active = $shops->firstWhere('id', $this->shopId);
        if (! $this->custom && $active !== null) {
            // With the shops' own rules the control shows the selected shop's horizon (the plan itself ignores this value).
            $this->horizon = ForecastService::horizon($active['horizon']);
        }
        $charts = $this->charts(! $ready || $shops->isEmpty(), $shops, $active);

        return view('livewire.ordering.index', [
            'heat' => $charts['heat'],
            'detail' => $charts['detail'],
            'openItem' => $this->openItem,
            'noShops' => $noShops,
            'generated' => $this->generated,
            'horizon' => $this->horizon,
            'custom' => $this->custom,
            'horizons' => range(1, ForecastService::MAX_HORIZON),
            'tab' => $this->tab,
            'generated_at' => $plan['generated_at'],
            'warehouseConfigured' => $plan['warehouse_configured'],
            'warehouseName' => Warehouse::shop()?->name,
            'kpi' => $plan['kpi'],
            'shops' => $shops,
            'active' => $active,
            'drafts' => Order::query()->where('status', OrderStatus::Draft->value)->pluck('id', 'shop_id')->all(),
            'pick' => $plan['pick'],
            'notice' => $this->notice,
        ]);
    }

    /**
     * Presentation-only shaping of the (cached) forecast rows for the charts: the cover-days heat map
     * (shops x the 12 most important items (ABC class and urgency)) and the series of the expanded item. No calculation of
     * its own: values are read from ForecastService output as they are.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $shops
     * @param  array<string, mixed>|null  $active
     * @return array{heat: array<string, mixed>|null, detail: array<string, mixed>|null}
     */
    private function charts(bool $skip, $shops, ?array $active): array
    {
        $out = ['heat' => null, 'detail' => null];
        if ($skip) {
            return $out;
        }

        $forecast = $this->forecast();
        $shopIds = $shops->pluck('id')->all();
        $score = [];
        $names = [];
        $cells = [];
        $detail = null;
        foreach ($forecast['rows'] as $r) {
            if (! in_array($r['shop_id'], $shopIds, true)) {
                continue;
            }
            // Importance of an item across the shops: ABC class (A 4, B 2, C 1) plus 3 per shop where it is out/critical.
            $score[$r['item_id']] = ($score[$r['item_id']] ?? 0)
                + (['A' => 4, 'B' => 2][$r['abc']] ?? 1)
                + (in_array($r['level'], ['out', 'critical'], true) ? 3 : 0);
            $names[$r['item_id']] = $r['item'];
            $cells[$r['shop_id']][$r['item_id']] = [
                'cover' => $r['cover_days'],
                'level' => $r['level'],
                'estimated' => $r['est'],
                'min' => $r['min'],
                'speed' => $r['speed'],
            ];
            if ($this->openItem !== null && $active && $r['shop_id'] === $active['id'] && $r['item_id'] === $this->openItem) {
                $detail = [
                    'item' => $r['item'],
                    'position' => (float) $r['position'],
                    'min' => (float) $r['min'],
                    'F' => array_values($r['F']),
                    'f' => array_values($r['f']),
                    'today_rem' => (float) $r['today_rem'],
                    'stockout_in' => $r['stockout_in'],
                    'rules_only' => $r['rules_only'],
                    'days' => array_map(fn (int $d) => __(now()->addDays($d)->locale('en')->format('D')), range(0, ForecastService::MAX_HORIZON)),
                ];
            }
        }

        arsort($score);
        $top = array_slice(array_keys($score), 0, 12);
        if ($top !== []) {
            $out['heat'] = [
                'items' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id]], $top),
                'shops' => $shops->map(fn (array $s) => [
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'cells' => array_map(fn ($id) => $cells[$s['id']][$id] ?? null, array_combine($top, $top)),
                ])->all(),
            ];
        }
        $out['detail'] = $detail;

        return $out;
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $warehouse = Warehouse::shopId();
        $ids = ShopAccess::ids();

        // Without the selector every shop is planned with its own rule horizon (the key carries the rules).
        return Cache::remember(
            $this->key('plan', $ids, $warehouse).($this->custom ? '.h'.$this->horizon : '.rules'),
            $this->ttl(),
            fn () => app(OrderRecommendations::class)->plan($this->forecast(), $this->custom ? $this->horizon : null, $warehouse),
        );
    }

    /** @return array<string, mixed> */
    private function forecast(): array
    {
        $warehouse = Warehouse::shopId();
        $ids = ShopAccess::ids();

        return Cache::remember(
            $this->key('forecast', $ids, $warehouse),
            $this->ttl(),
            fn () => app(ForecastService::class)->build($ids, $warehouse === null ? [] : [$warehouse]),
        );
    }

    /** @param  array<int, int>|null  $ids */
    private function key(string $name, ?array $ids, ?int $warehouse): string
    {
        return ShopAccess::salesKey('ordering.'.$name, 'w'.($warehouse ?? 0).'.o'.$this->ordersVersion().'.r'.OrderRule::signature().'.'.$this->runKey, $ids);
    }

    /** Bumped when an order is confirmed (in-transit / warehouse figures change); see the Orders pages. */
    public static function bumpOrdersVersion(): void
    {
        Cache::forever(self::ORDERS_VERSION_KEY, (int) Cache::get(self::ORDERS_VERSION_KEY, 0) + 1);
    }

    private function ordersVersion(): int
    {
        return (int) Cache::get(self::ORDERS_VERSION_KEY, 0);
    }

    private function ttl(): int
    {
        return max(60, min(600, (int) config('inventory.ordering_cache_seconds', 300)));
    }

    /** @return array<string, mixed> */
    private function emptyPlan(): array
    {
        return [
            'generated_at' => null,
            'horizon' => $this->horizon,
            'warehouse_configured' => Warehouse::isConfigured(),
            'kpi' => ['shops' => 0, 'lines' => 0, 'items' => 0, 'out' => 0, 'critical' => 0, 'soon' => 0, 'low' => 0, 'short_items' => 0, 'short_lines' => 0, 'starved' => 0],
            'shops' => [],
            'pick' => [],
        ];
    }
}
