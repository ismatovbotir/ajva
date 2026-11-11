<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * The single source of truth for the menu (sidebar, mobile drawer) and for the "Pages" results of the global search.
 * Items whose route is not registered are dropped, and items are hidden from roles that cannot open them (default:
 * admin + operator; a group's `roles` apply to its items unless an item sets its own). A `single` group is one link
 * without a heading (Home). The /monitor screen has no menu item: admins manage monitors in Settings > Monitors, and
 * monitor-only accounts land on it after signing in (they get no menu at all).
 */
class Navigation
{
    /**
     * @return array<int, array{label: string, single?: bool, items: array<int, array{label: string, route: string, icon: string, roles?: array<int, string>}>}>
     */
    public static function groups(?string $role): array
    {
        return collect([
            ['label' => 'Home', 'single' => true, 'items' => [
                ['label' => 'Home', 'route' => 'dashboard', 'icon' => 'home'],
            ]],
            ['label' => 'Sales', 'items' => [
                ['label' => 'Receipts', 'route' => 'receipts.index', 'icon' => 'receipt'],
                ['label' => 'Receipt analytics', 'route' => 'receipt-analytics.index', 'icon' => 'chart-pie'],
                ['label' => 'Analytics', 'route' => 'analytics.index', 'icon' => 'chart'],
            ]],
            ['label' => 'Sales growth', 'items' => [
                ['label' => 'Sales gaps', 'route' => 'sales-gaps.index', 'icon' => 'trending-up'],
                ['label' => 'Markdown and promo', 'route' => 'markdowns.index', 'icon' => 'receipt-percent'],
            ]],
            ['label' => 'Stock and supply', 'items' => [
                ['label' => 'Out of stock', 'route' => 'warehouse.out-of-stock', 'icon' => 'archive', 'roles' => ['admin']],
                ['label' => 'Ordering', 'route' => 'ordering.index', 'icon' => 'sparkles', 'roles' => ['admin']],
                ['label' => 'Orders', 'route' => 'orders.index', 'icon' => 'clipboard'],
                ['label' => 'Stock without sales', 'route' => 'unsold-stock.index', 'icon' => 'inbox'],
                ['label' => 'Assortment', 'route' => 'assortment.index', 'icon' => 'layers'],
            ]],
            ['label' => 'Catalog', 'items' => [
                ['label' => 'Shops', 'route' => 'shops.index', 'icon' => 'shop'],
                ['label' => 'Items', 'route' => 'items.index', 'icon' => 'box'],
                ['label' => 'Groups', 'route' => 'groups.index', 'icon' => 'group'],
                ['label' => 'Categories', 'route' => 'categories.index', 'icon' => 'category'],
                ['label' => 'Prices', 'route' => 'prices.index', 'icon' => 'currency'],
                ['label' => 'Pos', 'route' => 'pos.index', 'icon' => 'pos'],
            ]],
            ['label' => 'Monitoring', 'roles' => ['admin'], 'items' => [
                ['label' => 'Tasks', 'route' => 'monitoring.tasks', 'icon' => 'clock'],
                ['label' => 'Data health', 'route' => 'data-health.index', 'icon' => 'pulse'],
                ['label' => 'Items sync log', 'route' => 'monitoring.items-sync', 'icon' => 'sync'],
                // Built by the order-receiving feature; the entry appears only once its route exists.
                ['label' => 'Unconfirmed orders', 'route' => 'monitoring.unconfirmed-orders', 'icon' => 'exclamation'],
            ]],
            ['label' => 'Security', 'roles' => ['admin'], 'items' => [
                ['label' => 'Security warnings', 'route' => 'security.warnings', 'icon' => 'shield'],
            ]],
            ['label' => 'Telegram', 'roles' => ['admin'], 'items' => [
                ['label' => 'Telegram status', 'route' => 'telegram.status', 'icon' => 'signal'],
                ['label' => 'Telegram users', 'route' => 'telegram.users', 'icon' => 'user-circle'],
                ['label' => 'Telegram messages', 'route' => 'telegram.messages', 'icon' => 'chat'],
            ]],
            ['label' => 'Settings', 'roles' => ['admin'], 'items' => [
                ['label' => 'MCP server', 'route' => 'settings.mcp', 'icon' => 'adjustments'],
                ['label' => 'Monitors', 'route' => 'settings.monitors', 'icon' => 'monitor'],
                ['label' => 'Warehouse', 'route' => 'settings.warehouse', 'icon' => 'truck'],
                ['label' => 'Order rules', 'route' => 'settings.order-rules', 'icon' => 'cog'],
                ['label' => 'Receipts migration (1C)', 'route' => 'settings.receipts-sync', 'icon' => 'refresh'],
                ['label' => 'Users', 'route' => 'users.index', 'icon' => 'users'],
            ]],
        ])->map(function ($group) use ($role) {
            $groupRoles = $group['roles'] ?? ['admin', 'operator'];
            $group['items'] = array_values(array_filter(
                $group['items'],
                fn ($i) => Route::has($i['route'])
                    && in_array($role, $i['roles'] ?? $groupRoles, true)
            ));

            return $group;
        })->filter(fn ($g) => count($g['items']) > 0)->values()->all();
    }
}
