<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Items\Show as ItemsShow;
use App\Livewire\Monitor;
use App\Livewire\Prices\Index as PricesIndex;
use App\Livewire\SalesBoard;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Support\MonitorSettings;
use App\Support\ProfitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProfitAccessTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private Shop $shop;

    private Price $cost;

    private Price $retail;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 15:30:00');

        $shop = $this->shop = Shop::factory()->create(['name' => 'Shop Alpha']);
        $this->item = Item::factory()->create(['name' => 'Cola']);
        $this->cost = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Purchase', 'is_sell' => false]);
        $this->retail = Price::factory()->create(['id' => 99, 'name' => 'Retail', 'is_sell' => true]);
        ItemPrice::factory()->create(['item_id' => $this->item->id, 'price_id' => $this->cost->id, 'value' => 10]);
        ItemPrice::factory()->create(['item_id' => $this->item->id, 'price_id' => $this->retail->id, 'value' => 8]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $this->item->id, 'qty' => 5]);

        $receipt = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => 100, 'created_at' => '2026-10-06 10:00:00']);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $this->item->id, 'qty' => 2, 'total' => 100, 'storno' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An operator assigned to the only shop, so scoping hides nothing here. */
    private function operator(bool $profit = false): User
    {
        $user = $profit ? User::factory()->operator()->withProfit()->create() : User::factory()->operator()->create();
        $user->shops()->sync([$this->shop->id]);

        return $user;
    }

    /** viewData() throws for a key that is absent, which is exactly what "no profit payload" means. */
    private function profitOf($component): ?array
    {
        try {
            return $component->viewData('profit');
        } catch (\ErrorException) {
            return null;
        }
    }

    public function test_helper(): void
    {
        $this->assertTrue(ProfitAccess::allowed(User::factory()->create()));
        $this->assertTrue(ProfitAccess::allowed($this->operator(true)));
        $this->assertFalse(ProfitAccess::allowed($this->operator()));
        $this->assertFalse(ProfitAccess::allowed(User::factory()->monitor()->create()));
        $this->assertTrue(ProfitAccess::allowed(User::factory()->monitor()->withProfit()->create()));
        $this->assertFalse(ProfitAccess::allowed(null));
        $this->assertNotSame(ProfitAccess::profitKey(true), ProfitAccess::profitKey(false));
    }

    public function test_dashboard_without_the_permission_has_no_profit_data_in_view_data_or_html(): void
    {
        $component = Livewire::actingAs($this->operator())->test(Dashboard::class);

        $this->assertNull($component->viewData('margins'));
        $this->assertNull($component->viewData('belowCost'));
        $this->assertSame(['out_of_stock'], array_keys($component->viewData('health')));

        $html = $component->html();
        foreach (['Stock value at cost', 'Stock value by shop', 'Margin by item', 'Below cost', 'Pricing health', 'Profit by shop'] as $text) {
            $this->assertStringNotContainsString(__($text), $html, $text);
        }
        $this->assertStringNotContainsString(__('Margin'), $html);
        $this->assertStringNotContainsString('Cost missing', $html);
        // Cost 10 / retail 8 would be a below-cost row for someone allowed to see it.
        $this->assertStringContainsString(__('Out of stock'), $html);
    }

    public function test_dashboard_with_the_permission_and_admin_see_everything(): void
    {
        foreach ([$this->operator(true), User::factory()->create()] as $user) {
            $component = Livewire::actingAs($user)->test(Dashboard::class);

            $this->assertSame(1, $component->viewData('belowCost')['count']);
            $this->assertNotNull($component->viewData('margins'));
            $this->assertArrayHasKey('value', $component->viewData('health'));
            $component->assertSee(__('Stock value at cost'))->assertSee(__('Margin by item'))->assertSee(__('Profit'));
        }
    }

    public function test_sales_board_and_monitor_payloads_omit_profit_and_skip_cost_queries(): void
    {
        foreach ([SalesBoard::class, Monitor::class] as $class) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $component = Livewire::actingAs($this->operator())->test($class);
            $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
            DB::disableQueryLog();

            $this->assertStringNotContainsString('item_prices', $queries, $class);
            $this->assertArrayNotHasKey('profit', $component->viewData('topItems')['all'][0], $class);
            $this->assertArrayNotHasKey('profit', $component->viewData('trend')[0], $class);
            $this->assertNull($this->profitOf($component), $class);
            $this->assertStringNotContainsString(__('Margin'), $component->html(), $class);
            $this->assertStringNotContainsString('Cost missing', $component->html(), $class);

            Cache::flush();
            foreach ([$this->operator(true), User::factory()->create()] as $allowed) {
                $full = Livewire::actingAs($allowed)->test($class);
                $this->assertEquals(80, $this->profitOf($full)['total'], $class);
                $this->assertArrayHasKey('profit', $full->viewData('topItems')['all'][0], $class);
            }
        }
    }

    public function test_monitor_role_follows_the_flag_on_the_signed_in_monitor(): void
    {
        $without = Livewire::actingAs(User::factory()->monitor()->create())->test(Monitor::class);
        $this->assertNull($this->profitOf($without));
        $this->assertFalse($without->viewData('showProfit'));
        $without->assertSee(__('Peak hour'));

        $with = Livewire::actingAs(User::factory()->monitor()->withProfit()->create())->test(Monitor::class);
        $this->assertTrue($with->viewData('showProfit'));
        $this->assertEquals(80, $this->profitOf($with)['total']);
    }

    public function test_users_with_and_without_the_permission_never_share_cached_payloads(): void
    {
        $plain = $this->operator();
        $rich = $this->operator(true);

        foreach ([SalesBoard::class, Monitor::class] as $class) {
            // Both orders, same cache store, same day.
            foreach ([[$rich, $plain], [$plain, $rich]] as $order) {
                Cache::flush();
                foreach ($order as $user) {
                    $component = Livewire::actingAs($user)->test($class);
                    $this->assertSame($user->is($rich), $this->profitOf($component) !== null, $class);
                }
            }
        }

        Cache::flush();
        foreach ([$rich, $plain, $rich, $plain] as $user) {
            $health = Livewire::actingAs($user)->test(Dashboard::class)->viewData('health');
            $this->assertSame($user->is($rich), array_key_exists('value', $health));
        }
    }

    public function test_item_prices_tab_and_price_types_hide_the_cost_price(): void
    {
        $plain = Livewire::actingAs($this->operator())->test(ItemsShow::class, ['item' => $this->item]);
        $this->assertSame([$this->retail->id], $plain->viewData('itemPrices')->pluck('price_id')->all());
        $plain->assertDontSee('Purchase');

        $rich = Livewire::actingAs($this->operator(true))->test(ItemsShow::class, ['item' => $this->item]);
        $this->assertCount(2, $rich->viewData('itemPrices'));

        Livewire::actingAs($this->operator())->test(PricesIndex::class)
            ->assertDontSee('Purchase')->assertSee('Retail');
        Livewire::actingAs(User::factory()->create())->test(PricesIndex::class)->assertSee('Purchase');
    }

    public function test_public_monitor_keeps_its_own_switch(): void
    {
        $settings = app(MonitorSettings::class);
        $token = $settings->generateToken();

        $settings->setShowProfit(false);
        $this->assertNull($this->profitOf(Livewire::test(Monitor::class, ['token' => $token])));

        $settings->setShowProfit(true);
        $this->assertEquals(80, $this->profitOf(Livewire::test(Monitor::class, ['token' => $token]))['total']);
    }

    public function test_users_modal_saves_and_loads_the_switch(): void
    {
        $admin = User::factory()->create();
        $target = $this->operator();

        $component = Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('edit', $target->id)
            ->assertSet('canSeeProfit', false)
            ->set('canSeeProfit', true)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue($target->fresh()->can_see_profit);

        $component->call('edit', $target->id)->assertSet('canSeeProfit', true)
            ->assertSee(__('Profit, cost prices and margins are hidden from users without this permission.'))
            ->set('canSeeProfit', false)->call('save');
        $this->assertFalse($target->fresh()->can_see_profit);

        // Admins are always on and the form never changes their stored flag.
        $other = User::factory()->create();
        $component->call('edit', $other->id)->assertSee(__('Admins always see profit.'))->set('canSeeProfit', false)->call('save');
        $this->assertTrue($other->fresh()->canSeeProfit());

        $component->call('create')->set('name', 'N')->set('email', 'n@example.com')->set('password', 'secret-pass')
            ->set('role', 'operator')->set('canSeeProfit', true)->call('save')->assertHasNoErrors();
        $this->assertTrue(User::query()->where('email', 'n@example.com')->first()->can_see_profit);

        // The list marks users who have it.
        $component->assertSee(__('Profit'));
    }
}
