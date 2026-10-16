<?php

namespace App\Monitor;

/**
 * The single source of truth for monitor widgets. Saved layouts are validated against it
 * (LayoutValidator), the builder palette is rendered from it, and MonitorData picks its builder
 * and datasets from it. A new widget = one entry here + one payload method in WidgetPayloads.
 *
 * Grid: 12 columns. Sizes are [w, h] in grid cells. Comparison widgets return {now, prev_day, same_weekday_lw},
 * each cut at the same time of day (see Comparison). Inventory widgets use the Ordering forecast rule
 * (ForecastService + OrderRecommendations, horizon config inventory.order_horizon_days); they have no
 * baseline because no daily stock history of the past is kept for the future-looking forecast.
 */
class WidgetCatalog
{
    public const COLUMNS = 12;

    public const MAX_WIDGETS = 16;

    public const MAX_EXPENSIVE = 4;

    public const MAX_HEATMAPS = 2;

    public const TOP_N_MAX = 15;

    public const TOP_N_DEFAULT = 10;

    private const ASSORTMENT_RULE = 'Rule: the last 12 whole weeks of successful sales (ABC by revenue share, XYZ by weekly variation). Stock as of the last sync.';

    private const UNSOLD_RULE = 'Rule: current stock above 0 and no successful sale in the last 60 days. Items that just arrived also appear.';

    private const INVENTORY_RULE = 'Rule: the Ordering forecast (items with an order rule, horizon :days days). Stock as of the last 1C sync.';

    /** @var array<string, Widget>|null */
    private static ?array $all = null;

    /** @return array<string, Widget> */
    public static function all(): array
    {
        return self::$all ??= self::definitions();
    }

    public static function find(?string $key): ?Widget
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /** @return array<string, array<int, Widget>> widgets by (translated) group, in catalog order */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::all() as $widget) {
            $groups[$widget->groupLabel()][] = $widget;
        }

        return $groups;
    }

    /** @return array<string, Widget> */
    private static function definitions(): array
    {
        $defs = [
            self::make('sales_revenue', 'Sales total', 'Sales', 'Revenue of successful sale receipts today, against yesterday and the same weekday last week.', 'single', ['kpi', 'sparkline'], 'receipts',
                comparison: true, ttl: 25, spark: ['sparkline' => 'trend']),
            self::make('sales_net', 'Net sales', 'Sales', 'Sales minus refunds today, against yesterday and the same weekday last week.', 'single', ['kpi'], 'receipts',
                comparison: true, ttl: 25),
            self::make('receipt_count', 'Receipts', 'Sales', 'Number of sale receipts today, against yesterday and the same weekday last week.', 'single', ['kpi', 'sparkline'], 'receipts',
                comparison: true, ttl: 25, spark: ['sparkline' => 'trend']),
            self::make('avg_check', 'Average check', 'Sales', 'Sales total divided by the number of sale receipts.', 'single', ['kpi', 'sparkline'], 'receipts',
                comparison: true, ttl: 25, spark: ['sparkline' => 'trend']),
            self::make('basket_size', 'Basket size', 'Sales', 'Positions per sale receipt.', 'single', ['kpi'], 'receipts',
                comparison: true, ttl: 25),
            self::make('hourly_sales', 'Sales by hour', 'Sales', 'Today, yesterday and the same weekday last week, hour by hour.', 'series', ['line', 'bar'], 'hourly',
                comparison: true, ttl: 60, size: [8, 4], min: [4, 3], max: [12, 8], options: ['metric']),
            self::make('shop_leaderboard', 'Shops', 'Sales', 'Sales per shop with the change against yesterday and last week.', 'by_shop', ['bar', 'table', 'heatmap', 'donut'], 'receipts',
                comparison: true, ttl: 25, size: [6, 4], min: [3, 3], max: [12, 10], cost: 'medium'),
            self::make('top_items', 'Top items', 'Sales', 'Best-selling items today by quantity, with yesterday and last week at the same time.', 'by_item', ['table', 'bar'], 'top_items',
                comparison: true, ttl: 60, size: [6, 4], min: [3, 3], max: [12, 10], cost: 'expensive', options: ['top_n']),
            self::make('payment_mix', 'Payment types', 'Sales', 'Share of each payment type in the sales of today.', 'parts', ['donut', 'stacked_bar'], 'receipts',
                ttl: 25, size: [4, 3], min: [3, 2], max: [8, 6]),
            self::make('trend_8d', 'Last 8 days', 'Sales', 'Daily sales of the last 8 days; the first day is the same weekday as today.', 'series', ['line', 'bar'], 'trend',
                ttl: 300, size: [6, 3], min: [4, 2], max: [12, 8], options: ['metric']),
            self::make('refund_rate', 'Refund rate', 'Receipts', 'Refunds as a share of sales (amount). Amber from 2 %, red from 5 % (at least 10 receipts).', 'single', ['kpi', 'gauge'], 'receipts',
                comparison: true, ttl: 25),
            self::make('cancelled_rate', 'Cancelled receipts', 'Receipts', 'Cancelled receipts as a share of all receipts. Amber from 2 %, red from 5 % (at least 10 receipts).', 'single', ['kpi', 'gauge'], 'receipts',
                comparison: true, ttl: 25),
            self::make('discount_rate', 'Discount rate', 'Receipts', 'Discounts as a share of sales before the discount.', 'single', ['kpi', 'gauge'], 'receipts',
                comparison: true, ttl: 25),
            self::make('tills_active', 'Active tills', 'Receipts', 'Tills with a sale in the last 30 minutes out of all tills. Live, no baseline.', 'single', ['kpi', 'gauge'], 'receipts',
                ttl: 25),
            self::make('receipt_ticker', 'Latest receipts', 'Receipts', 'Newest receipts with their shop, till and payment. Not shown on public links.', 'events', ['ticker'], 'receipts',
                ttl: 25, size: [4, 4], min: [3, 3], max: [12, 10], publicSafe: false),
            self::make('gross_profit', 'Gross profit', 'Profit', 'Profit over cost-covered lines, against yesterday and last week.', 'single', ['kpi'], 'profit',
                comparison: true, ttl: 60, profit: true, cost: 'expensive'),
            self::make('margin_pct', 'Margin', 'Profit', 'Profit as a share of the cost-covered revenue. Amber 2 points, red 5 points below a baseline.', 'single', ['kpi', 'gauge'], 'profit',
                comparison: true, ttl: 60, profit: true, cost: 'medium'),
            self::make('profit_by_shop', 'Profit by shop', 'Profit', 'Profit and margin per shop.', 'by_shop', ['bar', 'table'], 'profit',
                comparison: true, ttl: 60, profit: true, cost: 'expensive', size: [6, 4], min: [3, 3], max: [12, 10]),
            self::make('stockout_count', 'Stock-outs', 'Inventory', 'Items with an order rule that are out of stock. Amber above 0, red above 5 % of ruled items.', 'single', ['kpi'], 'inventory',
                ttl: 120, cost: 'medium', rule: self::INVENTORY_RULE),
            self::make('low_cover_items', 'Low cover items', 'Inventory', 'Items that will run out in less than 3 days (red under 1 day).', 'by_item', ['table', 'bar'], 'inventory',
                ttl: 120, cost: 'medium', size: [6, 4], min: [3, 3], max: [12, 10], options: ['top_n'], rule: self::INVENTORY_RULE),
            self::make('replenishment_need', 'Replenishment need', 'Inventory', 'Order lines and units to send, per shop.', 'by_shop', ['bar', 'table'], 'inventory',
                ttl: 120, cost: 'expensive', size: [6, 4], min: [3, 3], max: [12, 10], rule: self::INVENTORY_RULE),
            self::make('wh_shortage', 'Warehouse shortage', 'Inventory', 'Items the main warehouse cannot send in full.', 'single', ['kpi'], 'inventory',
                ttl: 120, cost: 'medium', rule: self::INVENTORY_RULE),
            self::make('wh_pick_list', 'Pick list', 'Inventory', 'What to take from the warehouse, most urgent first.', 'by_item', ['table', 'bar'], 'inventory',
                ttl: 120, cost: 'expensive', size: [6, 4], min: [3, 3], max: [12, 10], options: ['top_n'], rule: self::INVENTORY_RULE),
            self::make('wh_cover', 'Warehouse coverage', 'Inventory', 'Share of the needed units the main warehouse can send.', 'single', ['kpi', 'gauge'], 'inventory',
                ttl: 120, cost: 'medium', rule: self::INVENTORY_RULE),
            self::make('abc_mix', 'ABC classes', 'Assortment', 'Share of sales of the A (first 80 %), B (next 15 %) and C (the rest) items over the last 12 weeks.', 'parts', ['donut', 'stacked_bar'], 'assortment',
                ttl: 600, cost: 'medium', size: [4, 3], min: [3, 2], max: [8, 6], rule: self::ASSORTMENT_RULE),
            self::make('abc_xyz_matrix', 'ABC x XYZ matrix', 'Assortment', 'Items per class: ABC by sales share, XYZ by steadiness of weekly sales (last 12 weeks).', 'by_shop', ['heatmap', 'table'], 'assortment',
                ttl: 600, cost: 'medium', size: [6, 3], min: [4, 3], max: [12, 6], rule: self::ASSORTMENT_RULE),
            self::make('a_items_out', 'A items out of stock', 'Assortment', 'Class A items (the 80 % of sales) that are out of stock in at least one shop. Red above 0.', 'single', ['kpi'], 'assortment',
                ttl: 600, cost: 'medium', rule: self::ASSORTMENT_RULE),
            self::make('unsold_items', 'Stock without sales', 'Assortment', 'Items in stock that did not sell at all in the last 60 days. Amber from 20 % of the stocked items.', 'single', ['kpi', 'gauge'], 'unsold',
                ttl: 600, cost: 'medium', rule: self::UNSOLD_RULE),
            self::make('unsold_by_group', 'Unsold by group', 'Assortment', 'Groups with the most items in stock that did not sell in the last 60 days.', 'by_item', ['table', 'bar'], 'unsold',
                ttl: 600, cost: 'medium', size: [6, 4], min: [3, 3], max: [12, 10], options: ['top_n'], rule: self::UNSOLD_RULE),
            self::make('wh_out_of_stock', 'Warehouse out of stock', 'Inventory', 'Items the main warehouse has run out of (from the daily 1C stock). Red when a class A item is out.', 'single', ['kpi'], 'wh_oos',
                ttl: 120, cost: 'medium'),
            self::make('customer_share', 'Receipts with a customer', 'Receipts', 'Share of today\'s sale receipts that carry a customer / loyalty card. Live, no baseline.', 'single', ['kpi', 'gauge'], 'customers',
                ttl: 60),
            self::make('sync_freshness', 'Stock sync', 'System', 'Age of the last 1C stock sync. Amber after 24 h, red after 48 h.', 'single', ['kpi'], 'sync',
                ttl: 300, size: [4, 3]),
        ];

        $all = [];
        foreach ($defs as $widget) {
            $all[$widget->key] = $widget;
        }

        return $all;
    }

    /**
     * @param  array<int, string>  $diagrams  first one is the default
     * @param  array<int, int>|null  $size
     * @param  array<int, int>|null  $min
     * @param  array<int, int>|null  $max
     * @param  array<string, string>  $spark
     * @param  array<int, string>  $options
     */
    private static function make(
        string $key, string $label, string $group, string $description, string $shape, array $diagrams, string $dataset,
        bool $comparison = false, int $ttl = 60, ?array $size = null, ?array $min = null, ?array $max = null,
        string $cost = 'cheap', bool $profit = false, bool $publicSafe = true, array $spark = [], array $options = [], ?string $rule = null,
    ): Widget {
        $single = $shape === 'single';
        $size ??= $single ? [3, 2] : [6, 4];

        return new Widget(
            key: $key,
            label: $label,
            group: $group,
            description: $description,
            shape: $shape,
            diagrams: array_map(fn (string $d) => Diagram::from($d), $diagrams),
            default: Diagram::from($diagrams[0]),
            w: $size[0],
            h: $size[1],
            min: $min ?? ($single ? [2, 1] : [3, 2]),
            max: $max ?? ($single ? [6, 4] : [12, 8]),
            ttl: $ttl,
            cost: $cost,
            dataset: $dataset,
            profit: $profit,
            comparison: $comparison,
            publicSafe: $publicSafe,
            options: $options,
            sparkDatasets: $spark,
            rule: $rule,
        );
    }
}
