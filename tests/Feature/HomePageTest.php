<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Livewire\Dashboard;
use App\Livewire\Home\Attention;
use App\Livewire\Home\Summary;
use App\Livewire\Home\Supply;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Order;
use App\Models\Pos;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Support\TaskRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** The role-aware Home (Dashboard route): admin and operator views, scope and profit gating. "Now" is 2026-10-07 12:00. */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $alpha;

    private Shop $beta;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->alpha = Shop::factory()->create(['name' => 'Alpha Mart']);
        $this->beta = Shop::factory()->create(['name' => 'Beta Mart']);
        TaskRuns::record(TaskRuns::HEARTBEAT, 'ok', 'pending=0');
        Cache::put('attention.security.high.2026-10-07', 0, 600);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function operator(Shop ...$shops): User
    {
        $user = User::factory()->operator()->create(['name' => 'Op Aziz']);
        $user->shops()->sync(array_map(fn (Shop $s) => $s->id, $shops));

        return $user;
    }

    /** Sale receipts with one line each (a cost price exists for the item, so a profit figure could be computed). */
    private function sales(Shop $shop, int $count, float $total, string $at, string $cashier = 'Cashier Zed'): void
    {
        $pos = Pos::query()->where('shop_id', $shop->id)->first() ?? Pos::factory()->create(['shop_id' => $shop->id]);
        $item = Item::query()->first() ?? Item::factory()->create();
        Receipt::factory()->count($count)->create(['shop_id' => $shop->id, 'pos_id' => $pos->id, 'total' => $total, 'created_at' => $at, 'cashier' => $cashier])
            ->each(fn (Receipt $r) => ReceiptItem::factory()->create(['receipt_id' => $r->id, 'item_id' => $item->id, 'qty' => 1, 'total' => $total, 'storno' => false]));
    }

    private function costOf(Item $item): void
    {
        $price = Price::factory()->create(['id' => (int) config('inventory.cost_price_id')]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $price->id, 'value' => 7]);
    }

    public function test_the_admin_home_shows_the_day_the_attention_panel_and_supply(): void
    {
        $this->sales($this->alpha, 12, 100, '2026-10-07 10:00:00');
        $this->sales($this->alpha, 12, 50, '2026-10-06 10:00:00');
        $this->sales($this->beta, 3, 100, '2026-10-07 09:00:00');

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
            ->assertSeeLivewire(Summary::class)->assertSeeLivewire(Attention::class)->assertSeeLivewire(Supply::class)
            ->assertSee('Today in all shops')->assertSee('Needs attention')->assertSee('All clear')
            ->assertSee('checked at 12:00')->assertSee('as of 12:00')
            ->assertSee('Top shops today')->assertSee('Alpha Mart')->assertSee('Beta Mart')
            ->assertSee('data-tile="sales"', false)->assertSee('data-tile="profit"', false)
            ->assertSee('data-tile="drafts"', false)->assertSee('data-tile="failed-sync"', false)
            ->assertSee('Sales details')->assertSee('Stock details');
    }

    public function test_the_sales_tile_carries_both_comparisons_and_drill_down_links(): void
    {
        $this->sales($this->alpha, 12, 100, '2026-10-07 10:00:00');   // 1200 today
        $this->sales($this->alpha, 12, 50, '2026-10-06 10:00:00');    // 600 yesterday: +100 %

        $component = Livewire::actingAs(User::factory()->create())->test(Summary::class);

        $component->assertSee('vs yesterday')->assertSee('vs last week')->assertSee('▲ +100.0%')
            ->assertSee('1 200')->assertSee(route('receipts.index'), false)->assertSee(route('shops.show', $this->alpha->id), false);
        // The week-ago day has no receipts: n/a, never a made-up percentage.
        $component->assertSee('n/a')->assertSee('Last week incomplete');
        $this->assertSame(1200.0, $component->viewData('cmp')['sum']['now']);
    }

    public function test_an_operator_sees_only_his_shops_no_cost_no_profit_and_no_cashier_names(): void
    {
        $item = Item::factory()->create();
        $this->costOf($item);
        $this->sales($this->alpha, 12, 100, '2026-10-07 10:00:00');
        $this->sales($this->beta, 12, 777, '2026-10-07 10:00:00', 'Cashier Beta');

        $html = $this->actingAs($this->operator($this->alpha))->get(route('dashboard'))->assertOk()
            ->assertSee('Today in your shops')->assertSee('Alpha Mart')
            ->assertDontSee('Beta Mart')->assertDontSee('9 324')->assertDontSee('Cashier Zed')->assertDontSee('Cashier Beta')
            ->assertDontSee('data-tile="profit"', false)->assertDontSee('data-tile="failed-sync"', false)->assertDontSee('data-tile="wh-out"', false)
            ->assertSee('data-block="hourly"', false)->assertSee('data-block="low-items"', false)->assertSee('Drafts to confirm')
            ->getContent();

        $this->assertStringNotContainsString('Stock value at cost', $html);
        $this->assertStringNotContainsString('Pricing health', $html);
        // 12 x 100 of his own shop: his total is 1 200, never the chain's 10 524.
        $this->assertStringContainsString('1 200', $html);
        $this->assertStringNotContainsString('10 524', $html);
    }

    public function test_the_profit_tile_is_admin_only_even_for_an_operator_with_the_profit_flag(): void
    {
        $this->sales($this->alpha, 12, 100, '2026-10-07 10:00:00');
        $rich = User::factory()->operator()->withProfit()->create();
        $rich->shops()->attach($this->alpha->id);

        Livewire::actingAs($rich)->test(Summary::class)->assertDontSeeHtml('data-tile="profit"');
        Livewire::actingAs(User::factory()->create())->test(Summary::class)->assertSeeHtml('data-tile="profit"');
    }

    public function test_an_operator_gets_his_drafts_and_the_ten_most_urgent_low_items_of_his_shops(): void
    {
        $op = $this->operator($this->alpha);
        for ($i = 1; $i <= 12; $i++) {
            $item = Item::factory()->create(['name' => 'Low item '.$i]);
            Stock::factory()->create(['shop_id' => $this->alpha->id, 'item_id' => $item->id, 'qty' => $i, 'stock_date' => '2026-10-07']);
            ItemOrderRule::query()->create(['shop_id' => $this->alpha->id, 'item_id' => $item->id, 'min' => 20, 'max' => 40]);
        }
        $foreign = Item::factory()->create(['name' => 'Beta only item']);
        Stock::factory()->create(['shop_id' => $this->beta->id, 'item_id' => $foreign->id, 'qty' => 0, 'stock_date' => '2026-10-07']);
        ItemOrderRule::query()->create(['shop_id' => $this->beta->id, 'item_id' => $foreign->id, 'min' => 20, 'max' => 40]);
        Order::query()->create(['shop_id' => $this->alpha->id, 'status' => OrderStatus::Draft]);
        Order::query()->create(['shop_id' => $this->beta->id, 'status' => OrderStatus::Draft]);

        $component = Livewire::actingAs($op)->test(Supply::class);

        $low = $component->viewData('lowItems');
        $this->assertCount(10, $low);
        $this->assertSame('Low item 1', $low[0]['item']);      // most urgent (lowest qty / min) first
        $this->assertNotContains('Beta only item', array_column($low, 'item'));
        $this->assertSame(1, $component->viewData('facts')['drafts']);
        $component->assertSee(route('orders.index'), false)->assertDontSee('Beta only item');
    }

    public function test_attention_rows_are_links_with_a_severity_word(): void
    {
        Order::query()->create(['shop_id' => $this->alpha->id, 'status' => OrderStatus::Draft]);
        \DB::table('orders')->update(['created_at' => now()->subHours(30)]);

        Livewire::actingAs(User::factory()->create())->test(Attention::class)
            ->assertSeeHtml('data-attention-row="drafts"')->assertSeeHtml('data-severity="red"')
            ->assertSee('Urgent')->assertSee('1 order drafts are not confirmed yet')
            ->assertSee(route('monitoring.unconfirmed-orders', ['type' => 'draft']), false)
            ->assertDontSee('All clear');
    }

    public function test_an_operator_without_shops_sees_the_hint_instead_of_all_clear(): void
    {
        Livewire::actingAs($this->operator())->test(Attention::class)->assertSee('No shops are assigned to you.')->assertDontSee('All clear');
    }

    public function test_loading_indicators_are_hidden_by_default_and_blocks_poll(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('wire:loading style="display: none;"', $html);
        $this->assertStringContainsString('wire:poll.120s', $html);
        $this->assertStringContainsString('wire:poll.60s', $html);
    }

    public function test_the_existing_stock_sections_are_still_on_the_page_inside_stock_details(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);

        $component->assertSee('Stock health')->assertSee('Stock by shop')->assertSee('Stock distribution');
    }

    public function test_the_monitor_role_keeps_its_landing(): void
    {
        $this->actingAs(User::factory()->monitor()->create())->get(route('dashboard'))->assertRedirect();
    }

    public function test_the_components_refuse_a_monitor_user(): void
    {
        Livewire::actingAs(User::factory()->monitor()->create())->test(Summary::class)->assertForbidden();
    }
}
