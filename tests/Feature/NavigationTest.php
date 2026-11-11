<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function labels(?string $role): array
    {
        return collect(Navigation::groups($role))->pluck('items')->flatten(1)->pluck('label')->all();
    }

    private function routes(?string $role): array
    {
        return collect(Navigation::groups($role))->pluck('items')->flatten(1)->pluck('route')->all();
    }

    public function test_admin_menu_is_regrouped_and_complete(): void
    {
        $groups = Navigation::groups('admin');

        $this->assertSame(['Home', 'Sales', 'Sales growth', 'Stock and supply', 'Catalog', 'Monitoring', 'Security', 'Telegram', 'Settings'], array_column($groups, 'label'));
        $byGroup = collect($groups)->mapWithKeys(fn ($g) => [$g['label'] => array_column($g['items'], 'route')]);

        $this->assertSame(['dashboard'], $byGroup['Home']);
        $this->assertSame(['receipts.index', 'receipt-analytics.index', 'analytics.index'], $byGroup['Sales']);
        $this->assertSame(['sales-gaps.index', 'markdowns.index'], $byGroup['Sales growth']);
        $this->assertSame(['warehouse.out-of-stock', 'ordering.index', 'orders.index', 'unsold-stock.index', 'assortment.index'], $byGroup['Stock and supply']);
        $this->assertSame(['shops.index', 'items.index', 'groups.index', 'categories.index', 'prices.index', 'pos.index'], $byGroup['Catalog']);
        $this->assertSame(['monitoring.tasks', 'data-health.index', 'monitoring.items-sync', 'monitoring.unconfirmed-orders'], $byGroup['Monitoring']);
        $this->assertSame(['security.warnings'], $byGroup['Security']);
        $this->assertSame(['telegram.status', 'telegram.users', 'telegram.messages'], $byGroup['Telegram']);
        $this->assertSame(['settings.mcp', 'settings.monitors', 'settings.warehouse', 'settings.order-rules', 'settings.receipts-sync', 'users.index'], $byGroup['Settings']);
        $this->assertTrue($groups[0]['single']);
    }

    public function test_an_operator_keeps_exactly_the_pages_he_could_open_before(): void
    {
        $this->assertEqualsCanonicalizing([
            'dashboard', 'receipts.index', 'receipt-analytics.index', 'analytics.index',
            'orders.index', 'unsold-stock.index', 'assortment.index', 'sales-gaps.index', 'markdowns.index',
            'shops.index', 'items.index', 'groups.index', 'categories.index', 'prices.index', 'pos.index',
        ], $this->routes('operator'));

        foreach (['ordering.index', 'warehouse.out-of-stock', 'monitoring.tasks', 'data-health.index', 'monitoring.unconfirmed-orders', 'security.warnings', 'telegram.status', 'settings.mcp', 'users.index'] as $adminOnly) {
            $this->assertNotContains($adminOnly, $this->routes('operator'));
        }
    }

    public function test_the_monitor_role_has_no_menu(): void
    {
        $this->assertSame([], Navigation::groups('monitor'));
        $this->assertSame([], Navigation::groups(null));
    }

    public function test_every_menu_item_has_its_own_icon(): void
    {
        $icons = collect(Navigation::groups('admin'))->pluck('items')->flatten(1)->pluck('icon');

        $this->assertSame($icons->count(), $icons->unique()->count(), 'duplicate icon: '.$icons->duplicates()->implode(', '));

        // Every icon is drawn by components/icon.blade.php (otherwise it falls back to the default list glyph).
        $source = file_get_contents(resource_path('views/components/icon.blade.php'));
        foreach ($icons as $icon) {
            $this->assertStringContainsString("@case('{$icon}')", $source, "missing icon {$icon}");
        }
    }

    public function test_rendered_menu_per_role_and_the_search_button(): void
    {
        app()->setLocale('en');

        $admin = $this->actingAs(User::factory()->create())->get('/')->assertOk();
        foreach (['Sales', 'Sales growth', 'Stock and supply', 'Catalog', 'Monitoring', 'Security', 'Telegram', 'Settings'] as $group) {
            $admin->assertSee($group);
        }
        $admin->assertSee('data-search-button', false)->assertSee('data-global-search', false);

        $operator = $this->actingAs(User::factory()->operator()->create())->get('/')->assertOk();
        $operator->assertSee('Stock and supply')->assertSee('Catalog')
            ->assertDontSee('Security warnings')->assertDontSee('Telegram status')->assertDontSee('Order rules');
        $operator->assertSee('data-global-search', false);
    }

    public function test_groups_start_collapsed_and_the_active_one_opens(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('receipts.index'))->assertOk()->getContent();

        // The fold state is the list of OPEN groups; the group of the current page adds itself on load.
        $this->assertStringContainsString("localStorage.getItem('navOpen')", $html);
        $this->assertStringContainsString("open = [...open, 'Sales']", $html);
        $this->assertStringNotContainsString("open = [...open, 'Catalog']", $html);
    }

    public function test_the_monitor_role_gets_no_search(): void
    {
        $monitor = User::factory()->monitor()->create();

        $this->actingAs($monitor)->get(route('monitor'))->assertDontSee('data-global-search', false);
    }
}
