<?php

namespace App\Monitor;

use Illuminate\Support\Str;

/**
 * The built-in templates ("role presets"). Code-defined, so there is nothing to seed: the monitor list
 * copies one into a real custom monitor (Presets::layout()). Entries are [key, w, h], [key, w, h, diagram] or [key, w, h, diagram, x, y] (fixed place);
 * they are flowed left to right over the 12 columns, wrapping into a new row when a widget does not fit.
 */
class Presets
{
    /** @return array<string, array{name: string, description: string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $definition) {
            $out[$key] = ['name' => $definition['name'](), 'description' => $definition['description']()];
        }

        return $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    public static function name(string $key): string
    {
        return self::definitions()[$key]['name']();
    }

    public static function description(string $key): string
    {
        return self::definitions()[$key]['description']();
    }

    /** @return array<int, array<string, mixed>> a ready layout with fresh widget ids */
    public static function layout(string $key): array
    {
        $widgets = [];
        foreach (self::definitions()[$key]['widgets'] as $entry) {
            $def = WidgetCatalog::find($entry[0]);
            $widgets[] = [
                'id' => (string) Str::uuid(),
                'key' => $def->key,
                'diagram' => $entry[3] ?? $def->default->value,
                'x' => $entry[4] ?? 0, 'y' => $entry[5] ?? 0, 'w' => $entry[1], 'h' => $entry[2],
                'fixed' => isset($entry[4]),
                'options' => Grid::defaultOptions($def),
            ];
        }

        $widgets = Grid::resolve(self::flow($widgets));

        return array_map(function (array $w) {
            unset($w['fixed']);

            return $w;
        }, $widgets);
    }

    /** Same layout with new widget ids (copying a template into a monitor). */
    public static function withFreshIds(array $layout): array
    {
        return array_map(fn (array $w) => ['id' => (string) Str::uuid()] + $w, $layout);
    }

    /** @param  array<int, array<string, mixed>>  $widgets */
    private static function flow(array $widgets): array
    {
        $x = 0;
        $y = 0;
        $rowHeight = 0;
        foreach ($widgets as &$widget) {
            if ($widget['fixed']) {
                continue;
            }
            if ($x + $widget['w'] > WidgetCatalog::COLUMNS) {
                $y += $rowHeight;
                $x = 0;
                $rowHeight = 0;
            }
            $widget['x'] = $x;
            $widget['y'] = $y;
            $x += $widget['w'];
            $rowHeight = max($rowHeight, $widget['h']);
        }
        unset($widget);

        return $widgets;
    }

    /** @return array<string, array{name: \Closure, description: \Closure, widgets: array<int, array<int, mixed>>}> */
    private static function definitions(): array
    {
        return [
            'executive' => [
                'name' => fn () => __('Executive'),
                'description' => fn () => __('Sales, receipts, payments, shops and profit for the owner.'),
                'widgets' => [
                    ['sales_revenue', 3, 2], ['sales_net', 3, 2], ['receipt_count', 3, 2], ['avg_check', 3, 2],
                    ['hourly_sales', 6, 4, null, 0, 2], ['payment_mix', 3, 4, null, 6, 2],
                    ['gross_profit', 3, 2, null, 9, 2], ['margin_pct', 3, 2, null, 9, 4],
                    ['shop_leaderboard', 6, 3, null, 0, 6], ['trend_8d', 6, 3, null, 6, 6],
                ],
            ],
            'receipts' => [
                'name' => fn () => __('Receipts'),
                'description' => fn () => __('Receipts today against yesterday: counts, average check, hours, payments, tills, refunds and the newest receipts.'),
                'widgets' => [
                    ['receipt_count', 3, 2], ['sales_revenue', 3, 2], ['avg_check', 3, 2], ['basket_size', 3, 2],
                    ['hourly_sales', 6, 3], ['payment_mix', 3, 3], ['receipt_ticker', 3, 3],
                    ['refund_rate', 3, 2], ['cancelled_rate', 3, 2], ['tills_active', 3, 2], ['discount_rate', 3, 2],
                    ['shop_leaderboard', 12, 3],
                ],
            ],
            'shop_manager' => [
                'name' => fn () => __('Shop manager'),
                'description' => fn () => __('The day of a shop: sales, refunds, latest receipts, stock risks and best sellers.'),
                'widgets' => [
                    ['sales_revenue', 3, 2], ['receipt_count', 3, 2], ['avg_check', 3, 2], ['refund_rate', 3, 2],
                    ['hourly_sales', 8, 3], ['receipt_ticker', 4, 3],
                    ['low_cover_items', 6, 5], ['top_items', 6, 5],
                ],
            ],
            'warehouse' => [
                'name' => fn () => __('Warehouse and replenishment'),
                'description' => fn () => __('Stock-outs, shortages and what to send to which shop.'),
                'widgets' => [
                    ['stockout_count', 4, 2], ['wh_shortage', 4, 2], ['wh_cover', 4, 2],
                    ['replenishment_need', 6, 4], ['wh_pick_list', 6, 4],
                    ['low_cover_items', 12, 4],
                ],
            ],
            'cashier_operations' => [
                'name' => fn () => __('Cashier operations'),
                'description' => fn () => __('Refunds, cancelled receipts, discounts, tills and the newest receipts.'),
                'widgets' => [
                    ['refund_rate', 3, 2], ['cancelled_rate', 3, 2], ['discount_rate', 3, 2], ['tills_active', 3, 2],
                    ['receipt_ticker', 4, 5],
                    ['shop_leaderboard', 8, 5, 'heatmap'],
                ],
            ],
            'fmcg_buyer' => [
                'name' => fn () => __('FMCG buyer'),
                'description' => fn () => __('Best sellers, items about to run out, margins and the freshness of the stock data.'),
                'widgets' => [
                    ['top_items', 6, 5], ['low_cover_items', 6, 5],
                    ['sync_freshness', 4, 3], ['margin_pct', 4, 3], ['profit_by_shop', 4, 3],
                ],
            ],
            'blank' => [
                'name' => fn () => __('Blank'),
                'description' => fn () => __('An empty screen: add the widgets you need.'),
                'widgets' => [],
            ],
        ];
    }
}
