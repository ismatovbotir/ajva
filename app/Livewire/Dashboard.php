<?php

namespace App\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    /**
     * Categorical palette — one hue per series/entity. Slot 1 is also the
     * single "primary" color used for non-comparative (one series) bars.
     */
    private const CATEGORY_COLORS = [
        '#2a78d6', // slot 1 — blue
        '#139a6e', // slot 2 — teal/green
        '#d17a00', // slot 3 — orange
        '#8e5cc9', // slot 4 — violet
        '#d6477b', // slot 5 — pink
        '#2f9bc0', // slot 6 — cyan (chart 3 only, extends beyond the 5 given donut slots)
        '#9a7b4f', // slot 7 — taupe (chart 3 only, extends beyond the 5 given donut slots)
    ];

    /** Muted neutral used only for "Other" fold-in segments, never a real series color. */
    private const COLOR_OTHER = '#8f8d85';

    /** Seconds the catalogue-wide aggregates are shared between viewers. */
    private const CACHE_TTL = 120;

    /** Max rows of the below-cost list sent to the browser (the count is always exact). */
    private const BELOW_COST_CAP = 200;

    /**
     * Status palette — reserved for state/severity indicators, always
     * distinct from the categorical series palette above. Only "critical"
     * and "warning" are used today (reorder-risk severity); "good"/"serious"
     * are reserved for future use but not referenced.
     */
    private const STATUS_COLORS = [
        'critical' => '#d03b3b',
        'warning' => '#d98200',
    ];

    public function render()
    {
        $byShop = $this->stockByShop();
        $byGroup = $this->stockByGroup();

        $shopMax = (float) ($byShop->max('total') ?: 1);
        $groupMax = (float) ($byGroup->max('total') ?: 1);

        $shopRows = $byShop->map(function (array $row) use ($shopMax) {
            $row['percent'] = $shopMax > 0 ? round($row['total'] / $shopMax * 100, 3) : 0;
            $row['label'] = $this->formatQty($row['total']);

            return $row;
        })->values()->all();

        $groupRows = $byGroup->map(function (array $row) use ($groupMax) {
            $row['percent'] = $groupMax > 0 ? round($row['total'] / $groupMax * 100, 3) : 0;
            $row['label'] = $this->formatQty($row['total']);

            return $row;
        })->values()->all();

        return view('livewire.dashboard', [
            'shopRows' => $shopRows,
            'shopMax' => $shopMax,
            'donut' => $this->buildDonut($byShop),
            'groupRows' => $groupRows,
            'groupMax' => $groupMax,
            'donutGroup' => $this->buildDonut($byGroup),
            'groupShop' => $this->stockByGroupAndShop($byShop, $byGroup),
            'barColor' => self::CATEGORY_COLORS[0],
            'exceptions' => $this->cached('exceptions', fn () => $this->reorderExceptions()),
            'coverage' => $this->cached('coverage', fn () => $this->ruleCoverage()),
            'margins' => $this->cached('margins', fn () => $this->itemMargins()),
            'belowCost' => $this->cached('below-cost', fn () => $this->belowCostPrices()),
            'health' => $this->cached('stock-health', fn () => $this->stockHealth()),
        ]);
    }

    /** Heavy catalogue-wide aggregates change only on 1C sync, so a short shared cache is enough. */
    private function cached(string $key, \Closure $callback): array
    {
        return Cache::remember('dashboard.'.$key, self::CACHE_TTL, $callback);
    }

    /**
     * Stock value at cost (stocks.qty x cost price, cost > 0 only) overall and
     * per shop, with the share of on-hand units whose item has no usable cost
     * so the number is never mistaken for the full inventory value. Also the
     * count of rule-covered (item, shop) pairs that are out of stock.
     *
     * @return array<string, mixed>
     */
    private function stockHealth(): array
    {
        $costId = (int) config('inventory.cost_price_id');

        $base = fn () => DB::table('stocks')
            ->leftJoin('item_prices as cost', function ($join) use ($costId) {
                $join->on('cost.item_id', '=', 'stocks.item_id')->where('cost.price_id', '=', $costId);
            })
            ->join('shops', 'shops.id', '=', 'stocks.shop_id')
            ->where('stocks.qty', '>', 0);

        $perShop = $base()
            ->groupBy('shops.id', 'shops.name')
            ->selectRaw(
                'shops.name as name, '
                .'SUM(CASE WHEN cost.value > 0 THEN stocks.qty * cost.value ELSE 0 END) as value, '
                .'SUM(stocks.qty) as units, '
                .'SUM(CASE WHEN cost.value > 0 THEN 0 ELSE stocks.qty END) as uncovered_units'
            )
            ->orderByDesc('value')
            ->get();

        $totalValue = (float) $perShop->sum('value');
        $units = (float) $perShop->sum('units');
        $uncovered = (float) $perShop->sum('uncovered_units');
        $max = (float) ($perShop->max('value') ?: 1);

        $missingItems = (int) DB::table('stocks')
            ->leftJoin('item_prices as cost', function ($join) use ($costId) {
                $join->on('cost.item_id', '=', 'stocks.item_id')->where('cost.price_id', '=', $costId);
            })
            ->where('stocks.qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('cost.value')->orWhere('cost.value', '<=', 0))
            ->distinct()
            ->count('stocks.item_id');

        $outOfStock = (int) DB::table('stocks')
            ->join('item_order_rules', function ($join) {
                $join->on('item_order_rules.item_id', '=', 'stocks.item_id')
                    ->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
            })
            ->where('stocks.qty', '<=', 0)
            ->where('item_order_rules.min', '>', 0)
            ->count();

        return [
            'value' => $totalValue,
            'value_label' => number_format($totalValue, 0, '.', ' '),
            'units_label' => $this->formatQty($units),
            'uncovered_percent' => $units > 0 ? round($uncovered / $units * 100, 1) : 0.0,
            'missing_items' => $missingItems,
            'out_of_stock' => $outOfStock,
            'shops' => $perShop->map(fn ($r) => [
                'name' => (string) $r->name,
                'label' => number_format((float) $r->value, 0, '.', ' '),
                'percent' => round((float) $r->value / $max * 100, 2),
            ])->values()->all(),
        ];
    }

    /**
     * Total stock quantity per shop, descending.
     *
     * @return Collection<int, array{id: int, name: string, total: float}>
     */
    private function stockByShop(): Collection
    {
        return DB::table('stocks')
            ->join('shops', 'shops.id', '=', 'stocks.shop_id')
            ->selectRaw('shops.id as id, shops.name as name, SUM(stocks.qty) as total')
            ->groupBy('shops.id', 'shops.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'total' => (float) $row->total,
            ]);
    }

    /**
     * Total stock quantity per item group, descending. Items with no group
     * (group_id IS NULL) roll up into a single "No group" bucket.
     *
     * @return Collection<int, array{id: int|null, name: string, total: float}>
     */
    private function stockByGroup(): Collection
    {
        return DB::table('stocks')
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
            ->selectRaw('items.group_id as id, groups.name as name, SUM(stocks.qty) as total')
            ->groupBy('items.group_id', 'groups.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id === null ? null : (int) $row->id,
                'name' => $row->id === null ? __('No group') : (string) $row->name,
                'total' => (float) $row->total,
            ]);
    }

    /**
     * Pivots total stock quantity per (group, shop) into "one row per group,
     * with a qty-by-shop stacked breakdown" shape for the stacked bar chart,
     * capped at the 7 highest-total shops (colors follow the shop across
     * every group's bar) with the remainder folded into "Other".
     */
    private function stockByGroupAndShop(Collection $byShop, Collection $byGroup): array
    {
        $raw = DB::table('stocks')
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->join('shops', 'shops.id', '=', 'stocks.shop_id')
            ->selectRaw('items.group_id as group_id, shops.id as shop_id, SUM(stocks.qty) as total')
            ->groupBy('items.group_id', 'shops.id')
            ->get();

        $shopTotalsByGroup = [];
        foreach ($raw as $row) {
            $groupKey = $row->group_id === null ? 'none' : (int) $row->group_id;
            $shopTotalsByGroup[$groupKey][(int) $row->shop_id] = (float) $row->total;
        }

        $topShops = $byShop->take(7)->values();
        $hasOther = $byShop->count() > $topShops->count();

        $legend = $topShops->map(fn (array $shop, int $i) => [
            'id' => $shop['id'],
            'name' => $shop['name'],
            'color' => self::CATEGORY_COLORS[$i],
        ])->values()->all();

        if ($hasOther) {
            $legend[] = ['id' => null, 'name' => __('Other'), 'color' => self::COLOR_OTHER];
        }

        $rows = [];
        $barMax = 0.0;

        foreach ($byGroup as $group) {
            $groupKey = $group['id'] === null ? 'none' : $group['id'];
            $shopTotals = $shopTotalsByGroup[$groupKey] ?? [];

            $segments = [];
            $cells = [];
            $otherTotal = 0.0;

            foreach ($topShops as $i => $shop) {
                $value = $shopTotals[$shop['id']] ?? 0.0;
                $cells[] = ['value' => $value, 'label' => $this->formatQty($value)];

                if ($value > 0) {
                    $segments[] = [
                        'name' => $shop['name'],
                        'color' => self::CATEGORY_COLORS[$i],
                        'value' => $value,
                        'percent_of_bar' => $group['total'] > 0 ? round($value / $group['total'] * 100, 3) : 0,
                        'label' => $this->formatQty($value),
                    ];
                }
            }

            foreach ($shopTotals as $shopId => $value) {
                if (! $topShops->contains('id', $shopId)) {
                    $otherTotal += $value;
                }
            }

            if ($hasOther) {
                $cells[] = ['value' => $otherTotal, 'label' => $this->formatQty($otherTotal)];

                if ($otherTotal > 0) {
                    $segments[] = [
                        'name' => __('Other'),
                        'color' => self::COLOR_OTHER,
                        'value' => $otherTotal,
                        'percent_of_bar' => $group['total'] > 0 ? round($otherTotal / $group['total'] * 100, 3) : 0,
                        'label' => $this->formatQty($otherTotal),
                    ];
                }
            }

            $barMax = max($barMax, $group['total']);

            $rows[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'total' => $group['total'],
                'total_label' => $this->formatQty($group['total']),
                'segments' => $segments,
                'cells' => $cells,
            ];
        }

        $barMax = $barMax ?: 1.0;

        foreach ($rows as &$row) {
            $row['bar_percent'] = round($row['total'] / $barMax * 100, 3);
        }
        unset($row);

        return [
            'rows' => $rows,
            'legend' => $legend,
            'max' => $barMax,
        ];
    }

    /**
     * Builds a donut ("share of total") view for any {id, name, total} row
     * collection (stock-by-shop or stock-by-group), capped at 6 segments:
     * the top 5 rows by total plus everything else folded into "Other" — a
     * full pie is illegible past ~6 segments, regardless of how many
     * distinct categories exist in the underlying data.
     *
     * @param  Collection<int, array{id: int|null, name: string, total: float}>  $rows
     */
    private function buildDonut(Collection $rows): array
    {
        $total = (float) $rows->sum('total');

        if ($total <= 0) {
            return ['segments' => [], 'total' => 0.0, 'radius' => 60, 'circumference' => 0.0];
        }

        $radius = 60;
        $circumference = 2 * M_PI * $radius;

        $top5 = $rows->take(5)->values();
        $otherTotal = (float) $rows->slice(5)->sum('total');

        $cumulative = 0.0;
        $segments = [];

        foreach ($top5 as $i => $row) {
            $percent = $row['total'] / $total * 100;
            $len = $percent / 100 * $circumference;

            $segments[] = [
                'name' => $row['name'],
                'value' => $row['total'],
                'label' => $this->formatQty($row['total']),
                'percent' => $percent,
                'color' => self::CATEGORY_COLORS[$i],
                'dasharray' => sprintf('%.3F %.3F', $len, $circumference - $len),
                'dashoffset' => -$cumulative,
            ];

            $cumulative += $len;
        }

        if ($otherTotal > 0) {
            $percent = $otherTotal / $total * 100;
            $len = $percent / 100 * $circumference;

            $segments[] = [
                'name' => __('Other'),
                'value' => $otherTotal,
                'label' => $this->formatQty($otherTotal),
                'percent' => $percent,
                'color' => self::COLOR_OTHER,
                'dasharray' => sprintf('%.3F %.3F', $len, $circumference - $len),
                'dashoffset' => -$cumulative,
            ];
        }

        return [
            'segments' => $segments,
            'total' => $total,
            'radius' => $radius,
            'circumference' => $circumference,
        ];
    }

    /**
     * Trims a summed quantity to a whole number when it has no meaningful
     * fractional part, otherwise keeps 2 decimals — avoids "30.000"-style
     * noise on chart labels for what are almost always whole-unit sums.
     */
    private function formatQty(float $value): string
    {
        if (abs($value - round($value)) < 0.005) {
            return (string) (int) round($value);
        }

        return number_format($value, 2, '.', '');
    }

    /** Formats a price/margin value to 2 decimals, no thousands separator. */
    private function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Ranked list of every (item, shop) combination currently at or below
     * its configured minimum threshold (`stocks.qty <= item_order_rules.min`),
     * most urgent first. Urgency is `qty / min` — the lower the ratio, the
     * closer to (or further past) a stockout. When `min` is 0 the ratio is
     * forced to 0 (undefined division aside, a rule with a 0 minimum being
     * triggered at all means `qty` is also 0 — an outright stockout, the
     * most urgent case there is). Capped at 20 rows; anything beyond that is
     * summarized as a "N more" count rather than silently dropped.
     *
     * @return array{rows: array, total: int, hidden: int}
     */
    private function reorderExceptions(): array
    {
        $cap = 20;

        $base = DB::table('stocks')
            ->join('item_order_rules', function ($join) {
                $join->on('item_order_rules.item_id', '=', 'stocks.item_id')
                    ->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
            })
            ->whereColumn('stocks.qty', '<=', 'item_order_rules.min');

        $total = (clone $base)->count();

        $rows = $base
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->join('shops', 'shops.id', '=', 'stocks.shop_id')
            ->selectRaw(
                'items.name as item_name, shops.name as shop_name, stocks.qty as qty, '.
                'item_order_rules.min as min, '.
                // "* 1.0" forces floating-point division on both SQLite and
                // MySQL/MariaDB — without it, SQLite truncates to integer
                // division when both operands have integer affinity (e.g.
                // 3 / 10 silently becoming 0 instead of 0.3).
                'CASE WHEN item_order_rules.min > 0 THEN (stocks.qty * 1.0) / item_order_rules.min ELSE 0 END as ratio'
            )
            ->orderBy('ratio')
            ->orderByRaw('(item_order_rules.min - stocks.qty) DESC')
            ->limit($cap)
            ->get()
            ->map(function ($row) {
                $qty = (float) $row->qty;
                $min = (float) $row->min;
                $ratio = (float) $row->ratio;
                $isCritical = $qty <= 0 || $ratio <= 0.5;
                $severity = $isCritical ? 'critical' : 'warning';

                return [
                    'item_name' => (string) $row->item_name,
                    'shop_name' => (string) $row->shop_name,
                    'qty_label' => $this->formatQty($qty),
                    'min_label' => $this->formatQty($min),
                    'severity' => $severity,
                    'severity_label' => $isCritical ? __('Critical') : __('Warning'),
                    'severity_color' => self::STATUS_COLORS[$severity],
                ];
            })
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'hidden' => max($total - count($rows), 0),
        ];
    }

    /**
     * What percentage of (item, shop) pairs that have a `stocks` row also
     * have a corresponding `item_order_rules` row — a data-completeness
     * signal, since an empty reorder-risk list can otherwise be mistaken for
     * "everything's fine" when it may really mean "no rules configured at
     * all". `stocks` is unique on (item_id, shop_id), so its row count is
     * already the distinct-pair count — no need to pull rows into PHP.
     *
     * @return array{total: int, covered: int, percent: float}
     */
    private function ruleCoverage(): array
    {
        $total = DB::table('stocks')->count();

        $covered = DB::table('stocks')
            ->join('item_order_rules', function ($join) {
                $join->on('item_order_rules.item_id', '=', 'stocks.item_id')
                    ->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
            })
            ->count();

        return [
            'total' => $total,
            'covered' => $covered,
            'percent' => $total > 0 ? round($covered / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Every selling price that is lower than the item's cost price — either
     * an intentional discount or a data mistake worth checking. One row per
     * (item, price type), so an item with several selling prices under cost
     * appears once per price type. Zero values are ignored (a price type that
     * is simply not set). Worst (deepest below cost) first; not capped, the
     * view scrolls.
     *
     * @return array{count: int, rows: array<int, array<string, string|float>>}
     */
    private function belowCostPrices(): array
    {
        $costPriceId = (int) config('inventory.cost_price_id');

        $query = DB::table('item_prices as sell')
            ->join('item_prices as cost', function ($join) use ($costPriceId) {
                $join->on('cost.item_id', '=', 'sell.item_id')->where('cost.price_id', '=', $costPriceId);
            })
            ->join('items', 'items.id', '=', 'sell.item_id')
            ->join('prices', 'prices.id', '=', 'sell.price_id')
            ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
            ->where('sell.price_id', '<>', $costPriceId)
            ->where('sell.value', '>', 0)
            ->where('cost.value', '>', 0)
            ->whereColumn('sell.value', '<', 'cost.value');

        $count = (clone $query)->count();

        $rows = $query
            ->orderByRaw('((sell.value - cost.value) * 1.0) / cost.value')
            ->limit(self::BELOW_COST_CAP)
            ->get([
                'items.name as item_name',
                'groups.name as group_name',
                'prices.name as price_name',
                'cost.value as cost_value',
                'sell.value as sell_value',
            ])
            ->map(function ($r) {
                $cost = (float) $r->cost_value;
                $sell = (float) $r->sell_value;
                $pct = round(($sell - $cost) / $cost * 100, 2);

                return [
                    'item_name' => (string) $r->item_name,
                    'group_name' => $r->group_name !== null ? (string) $r->group_name : __('No group'),
                    'price_name' => (string) $r->price_name,
                    'cost_label' => $this->formatMoney($cost),
                    'sell_label' => $this->formatMoney($sell),
                    'diff_label' => $this->formatMoney(round($sell - $cost, 2)),
                    'pct' => $pct,
                    'pct_label' => $this->formatMoney($pct).'%',
                ];
            })
            ->sortBy('pct')
            ->values()
            ->all();

        return ['count' => $count, 'rows' => $rows, 'hidden' => max($count - count($rows), 0)];
    }

    /**
     * Per-item cost vs. sell margin, based on `item_prices`. An item's cost
     * is its value for the cost price type (`config('inventory.cost_price_id')`,
     * price id 1 in 1C); its sell value is the highest value among all the
     * other price types (an item can carry several selling prices, so the
     * highest is used deterministically rather than an arbitrary row).
     * Margin % is only computed when both a (non-zero) cost and a sell value
     * exist; items missing one or both are tallied separately as a
     * data-completeness stat.
     *
     * @return array{best: array, worst: array, total_with_margin: int, missing_cost: int, missing_sell: int, missing_both: int}
     */
    private function itemMargins(): array
    {
        $costPriceId = (int) config('inventory.cost_price_id');

        // One row per item with its cost and highest selling price (derived
        // table); everything below is aggregated/limited in SQL so the whole
        // catalogue is never loaded into PHP.
        $perItem = DB::table('items')
            ->leftJoin('item_prices', 'item_prices.item_id', '=', 'items.id')
            ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
            ->selectRaw(
                'items.id as item_id, items.name as item_name, groups.name as group_name, '.
                'MAX(CASE WHEN item_prices.price_id = ? THEN item_prices.value END) as cost_value, '.
                'MAX(CASE WHEN item_prices.price_id <> ? THEN item_prices.value END) as sell_value',
                [$costPriceId, $costPriceId]
            )
            ->groupBy('items.id', 'items.name', 'groups.name');

        $stats = DB::query()->fromSub($perItem, 'm')
            ->selectRaw(
                'SUM(CASE WHEN cost_value IS NULL AND sell_value IS NULL THEN 1 ELSE 0 END) as missing_both, '.
                'SUM(CASE WHEN cost_value IS NULL AND sell_value IS NOT NULL THEN 1 ELSE 0 END) as missing_cost, '.
                'SUM(CASE WHEN cost_value IS NOT NULL AND sell_value IS NULL THEN 1 ELSE 0 END) as missing_sell, '.
                'SUM(CASE WHEN cost_value IS NOT NULL AND sell_value IS NOT NULL AND cost_value <> 0 THEN 1 ELSE 0 END) as with_margin'
            )
            ->first();

        // Zero-cost rows have an undefined margin %, so they are excluded
        // from the ranking (but are not "missing" data).
        $ranked = fn (string $direction) => DB::query()->fromSub($perItem, 'm')
            ->whereNotNull('cost_value')
            ->whereNotNull('sell_value')
            ->where('cost_value', '<>', 0)
            ->orderByRaw('((sell_value - cost_value) * 1.0) / cost_value '.$direction)
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $cost = (float) $row->cost_value;
                $sell = (float) $row->sell_value;
                $margin = $sell - $cost;
                $marginPct = round($margin / $cost * 100, 2);

                return [
                    'item_name' => (string) $row->item_name,
                    'group_name' => $row->group_name !== null ? (string) $row->group_name : __('No group'),
                    'cost_label' => $this->formatMoney($cost),
                    'sell_label' => $this->formatMoney($sell),
                    'margin_label' => $this->formatMoney(round($margin, 2)),
                    'margin_pct' => $marginPct,
                    'margin_pct_label' => $this->formatMoney($marginPct).'%',
                ];
            })
            ->values()
            ->all();

        return [
            'best' => $ranked('DESC'),
            'worst' => $ranked('ASC'),
            'total_with_margin' => (int) $stats->with_margin,
            'missing_cost' => (int) $stats->missing_cost,
            'missing_sell' => (int) $stats->missing_sell,
            'missing_both' => (int) $stats->missing_both,
        ];
    }
}
