<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Livewire\GlobalSearch;
use App\Models\Barcode;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Order;
use App\Models\Pos;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Shop $alpha;

    private Shop $beta;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->alpha = Shop::factory()->create(['name' => 'Alpha Mart']);
        $this->beta = Shop::factory()->create(['name' => 'Beta Mart']);
    }

    private function operator(Shop ...$shops): User
    {
        $user = User::factory()->operator()->create();
        $user->shops()->sync(array_map(fn (Shop $s) => $s->id, $shops));

        return $user;
    }

    private function search(User $user, string $query)
    {
        return Livewire::actingAs($user)->test(GlobalSearch::class)->set('query', $query);
    }

    private function group($component, string $key): array
    {
        return collect($component->viewData('groups'))->firstWhere('key', $key)['rows'] ?? [];
    }

    public function test_nothing_is_searched_below_three_characters(): void
    {
        Item::factory()->create(['name' => 'Cola classic']);

        $component = $this->search(User::factory()->create(), 'co');

        $this->assertSame([], $component->viewData('groups'));
        $component->assertSee('Type at least 3 characters.');
        $this->assertSame([], $this->search(User::factory()->create(), '  ')->viewData('groups'));
    }

    public function test_items_are_found_by_name_code_and_gtin_prefix_without_any_price(): void
    {
        $item = Item::factory()->create(['name' => 'Cola classic', 'mark' => 'COLA-77']);
        Barcode::factory()->create(['item_id' => $item->id, 'gtin' => '4780001112223']);
        $price = Price::factory()->create(['id' => (int) config('inventory.cost_price_id')]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $price->id, 'value' => 98765]);

        foreach (['cola cl', 'COLA-77', '478000111'] as $term) {
            $rows = $this->group($this->search(User::factory()->create(), $term), 'items');
            $this->assertSame('Cola classic', $rows[0]['title'], $term);
            $this->assertSame(route('items.show', $item->id), $rows[0]['url']);
        }

        // An operator finds items too (global by design) but never sees a price.
        $this->search($this->operator($this->alpha), 'cola cl')->assertSee('Cola classic')->assertDontSee('98765');
    }

    public function test_groups_hold_at_most_five_results(): void
    {
        Item::factory()->count(8)->sequence(fn ($s) => ['name' => 'Widget '.$s->index])->create();

        $this->assertCount(5, $this->group($this->search(User::factory()->create(), 'Widget'), 'items'));
    }

    public function test_orders_are_found_by_number_or_id_within_the_users_shops(): void
    {
        $mine = Order::query()->create(['shop_id' => $this->alpha->id, 'status' => OrderStatus::Draft]);
        $other = Order::query()->create(['shop_id' => $this->beta->id, 'status' => OrderStatus::Draft]);
        $op = $this->operator($this->alpha);

        $rows = $this->group($this->search($op, $mine->number()), 'orders');
        $this->assertSame($mine->number(), $rows[0]['title']);
        $this->assertSame(route('orders.show', $mine), $rows[0]['url']);
        $this->assertStringContainsString('Alpha Mart', $rows[0]['sub']);

        $this->assertSame([], $this->group($this->search($op, $other->number()), 'orders'));
        $this->assertSame([], $this->group($this->search($op, (string) str_pad((string) $other->id, 3, '0', STR_PAD_LEFT)), 'orders'));
        $this->assertSame($other->number(), $this->group($this->search(User::factory()->create(), strtolower($other->number())), 'orders')[0]['title']);
    }

    public function test_receipts_are_found_by_number_within_the_users_shops(): void
    {
        $pos = Pos::factory()->create(['shop_id' => $this->alpha->id]);
        $mine = Receipt::factory()->create(['shop_id' => $this->alpha->id, 'pos_id' => $pos->id, 'number' => 'R-A-1001']);
        $posB = Pos::factory()->create(['shop_id' => $this->beta->id]);
        Receipt::factory()->create(['shop_id' => $this->beta->id, 'pos_id' => $posB->id, 'number' => 'R-B-1001']);
        $op = $this->operator($this->alpha);

        $rows = $this->group($this->search($op, 'R-A-10'), 'receipts');
        $this->assertCount(1, $rows);
        $this->assertSame(route('receipts.show', $mine), $rows[0]['url']);
        $this->assertSame([], $this->group($this->search($op, 'R-B-10'), 'receipts'));
        $this->assertCount(1, $this->group($this->search(User::factory()->create(), 'R-B-10'), 'receipts'));
        $this->assertStringNotContainsString('Cashier', json_encode($rows));
    }

    public function test_shops_are_scoped(): void
    {
        $op = $this->operator($this->alpha);

        $this->assertSame(['Alpha Mart'], array_column($this->group($this->search($op, 'Mart'), 'shops'), 'title'));
        $this->assertEqualsCanonicalizing(['Alpha Mart', 'Beta Mart'], array_column($this->group($this->search(User::factory()->create(), 'Mart'), 'shops'), 'title'));
        $this->assertSame([], $this->group($this->search($this->operator(), 'Mart'), 'shops'));
    }

    public function test_pages_follow_the_menu_of_the_role(): void
    {
        $admin = $this->group($this->search(User::factory()->create(), 'ordering'), 'pages');
        $this->assertSame(route('ordering.index'), $admin[0]['url']);

        // An operator never gets an admin page, however he spells it.
        $operator = $this->operator($this->alpha);
        $this->assertSame([], $this->group($this->search($operator, 'ordering'), 'pages'));
        $this->assertSame([], $this->group($this->search($operator, 'security'), 'pages'));
        $this->assertSame([], $this->group($this->search($operator, 'users'), 'pages'));
        $this->assertSame(route('receipts.index'), $this->group($this->search($operator, 'receipts'), 'pages')[0]['url']);

        // Fuzzy: the letters in order.
        $this->assertContains(route('receipt-analytics.index'), array_column($this->group($this->search($operator, 'rcptanl'), 'pages'), 'url'));
        $this->assertContains(route('unsold-stock.index'), array_column($this->group($this->search($operator, 'stwsl'), 'pages'), 'url'));
    }

    public function test_results_are_plain_links_and_the_box_has_keyboard_hooks(): void
    {
        Item::factory()->create(['name' => 'Cola classic']);

        $component = $this->search(User::factory()->create(), 'cola cl');
        $component->assertSeeHtml('data-search-result')->assertSeeHtml('data-search-group="items"');

        $html = $this->actingAs(User::factory()->create())->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('@keydown.window.ctrl.k.prevent', $html);
        $this->assertStringContainsString('@keydown.arrow-down.prevent="move(1)"', $html);
        $this->assertStringContainsString('@keydown.escape.window', $html);
        $this->assertStringContainsString("\$event.key === '/'", $html);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="query"', $html);
        $this->assertStringContainsString('style="display: none;"', $html);
    }

    public function test_a_monitor_user_cannot_use_the_search(): void
    {
        Livewire::actingAs(User::factory()->monitor()->create())->test(GlobalSearch::class)->assertForbidden();
    }
}
