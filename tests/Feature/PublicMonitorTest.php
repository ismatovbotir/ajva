<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Livewire\MonitorScreen;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Monitor;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithMonitors;
use Tests\TestCase;

class PublicMonitorTest extends TestCase
{
    use InteractsWithMonitors;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function publicMonitor(array $attributes = []): Monitor
    {
        return Monitor::factory()->withLink()->create($attributes);
    }

    private function seedSale(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $shop = Shop::factory()->create(['name' => 'Chilonzor']);
        $item = Item::factory()->create(['name' => 'Cola']);
        $cost = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $cost->id, 'value' => 10]);
        $receipt = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => 100, 'created_at' => '2026-10-06 09:10:00']);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => 2, 'total' => 100, 'storno' => false]);
    }

    public function test_guest_sees_the_public_monitor_with_a_valid_token_and_headers_are_set(): void
    {
        $monitor = $this->publicMonitor();

        $response = $this->get('/monitor/'.$monitor->token)
            ->assertOk()
            ->assertSeeLivewire(MonitorScreen::class)
            ->assertSee('wire:poll.30s', false)
            ->assertSee($monitor->name)
            ->assertDontSee('/logout', false)
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('monitor').'"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">', false);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_every_type_renders_publicly_with_its_own_view(): void
    {
        foreach ([MonitorType::Receipts, MonitorType::Warehouse] as $type) {
            $monitor = $this->publicMonitor(['type' => $type]);
            $this->get('/monitor/'.$monitor->token)->assertOk()->assertDontSee(__('This screen is being prepared'))->assertSee($type->label());
        }

        $executive = $this->publicMonitor();
        $this->get('/monitor/'.$executive->token)->assertOk()->assertDontSee(__('This screen is being prepared'));
    }

    public function test_wrong_unknown_or_disabled_tokens_are_a_plain_404(): void
    {
        $this->get('/monitor/anything')->assertNotFound();   // nothing configured

        $monitor = $this->publicMonitor();
        $this->get('/monitor/'.str_replace('a', 'b', $monitor->token).'x')->assertNotFound();

        $monitor->update(['enabled' => false]);
        $this->get('/monitor/'.$monitor->token)->assertNotFound();
    }

    public function test_regenerating_invalidates_the_old_link_including_livewire_updates(): void
    {
        $monitor = $this->publicMonitor();
        $old = $monitor->token;
        $component = Livewire::test(MonitorScreen::class, ['token' => $old]);
        $component->call('$refresh')->assertOk();   // still fine

        $new = $monitor->regenerateToken();
        $this->assertNotSame($old, $new);

        $this->get('/monitor/'.$old)->assertNotFound();
        $this->get('/monitor/'.$new)->assertOk();

        $component->call('$refresh')->assertNotFound();   // the open screen's next poll
    }

    public function test_disabling_or_deleting_stops_an_open_screens_polling(): void
    {
        $monitor = $this->publicMonitor();
        $component = Livewire::test(MonitorScreen::class, ['token' => $monitor->token]);
        $monitor->update(['enabled' => false]);
        $component->call('$refresh')->assertNotFound();

        $other = $this->publicMonitor();
        $component = Livewire::test(MonitorScreen::class, ['token' => $other->token]);
        $other->delete();
        $component->call('$refresh')->assertNotFound();
    }

    public function test_the_token_and_monitor_cannot_be_changed_from_the_browser(): void
    {
        $monitor = $this->publicMonitor();
        $component = Livewire::test(MonitorScreen::class, ['token' => $monitor->token]);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('publicToken', 'other');
    }

    public function test_the_signed_in_monitor_id_is_locked_too(): void
    {
        $component = $this->screen(MonitorScreen::class, User::factory()->create());

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('monitorId', 999);
    }

    public function test_the_public_screen_uses_its_own_monitors_shops_and_profit_switch(): void
    {
        $this->seedSale();
        $other = Shop::factory()->create(['name' => 'Elsewhere']);
        $item = Item::query()->first();
        $receipt = Receipt::factory()->create(['shop_id' => $other->id, 'active' => true, 'sell' => true, 'total' => 900, 'created_at' => '2026-10-06 10:00:00']);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => 1, 'total' => 900, 'storno' => false]);

        $monitor = $this->publicMonitor();
        $monitor->shops()->sync([Shop::query()->where('name', 'Chilonzor')->value('id')]);

        $component = Livewire::test(MonitorScreen::class, ['token' => $monitor->token]);
        $this->assertEquals(100, $component->viewData('totals')['sum']);

        // Signed in as an operator with other shops: the public screen stays on its own selection.
        $this->actingAs(User::factory()->operator()->create());
        $this->assertEquals(100, Livewire::test(MonitorScreen::class, ['token' => $monitor->token])->viewData('totals')['sum']);
    }

    public function test_profit_is_hidden_on_the_public_screen_by_default_and_shown_when_switched_on(): void
    {
        $this->seedSale();
        $monitor = $this->publicMonitor(['show_profit' => false]);
        $token = $monitor->token;

        $hidden = $this->get('/monitor/'.$token)->assertOk()->getContent();
        foreach ([__('Profit'), __('Margin'), 'Cost missing', __('Cost missing for :count items — :percent% of revenue is not covered.', ['count' => 0, 'percent' => 0])] as $label) {
            $this->assertStringNotContainsString($label, $hidden);
        }
        $this->assertStringContainsString(__('Peak hour'), $hidden);
        $this->assertStringContainsString('Cola', $hidden);

        $component = Livewire::test(MonitorScreen::class, ['token' => $token]);
        $this->assertArrayNotHasKey('profit', $component->viewData('trend')[0]);
        $this->assertArrayNotHasKey('profit', $component->viewData('topItems')['all'][0]);

        $monitor->update(['show_profit' => true]);
        $shown = $this->get('/monitor/'.$token)->assertOk()->getContent();
        $this->assertStringContainsString(__('Profit'), $shown);
        $this->assertStringContainsString(__('Margin'), $shown);
        $this->assertStringNotContainsString(__('Peak hour'), $shown);
    }

    public function test_signed_in_monitor_follows_the_users_profit_permission_not_the_monitors_switch(): void
    {
        $this->seedSale();
        $monitor = $this->executiveMonitor([], ['show_profit' => false]);

        $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)
            ->assertOk()
            ->assertSee(__('Profit'))
            ->assertSee('href="'.route('dashboard').'"', false);

        $this->actingAs(User::factory()->operator()->create())->get('/monitors/'.$monitor->id)->assertOk()->assertDontSee(__('Margin'));
        $this->actingAs(User::factory()->monitor()->create())->get('/monitors/'.$monitor->id)->assertOk();
    }

    public function test_monitor_shows_each_shops_share_of_sales_as_a_pie(): void
    {
        $user = User::factory()->create();
        $monitor = $this->executiveMonitor();
        $a = Shop::factory()->create(['name' => 'Shop Alpha']);
        $b = Shop::factory()->create(['name' => 'Shop Beta']);
        foreach ([[$a, 300], [$b, 100]] as [$shop, $total]) {
            Receipt::factory()->create(['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => $total, 'created_at' => now()->subMinute()]);
        }
        // Refunds and cancelled receipts must not count towards the share.
        Receipt::factory()->create(['shop_id' => $b->id, 'active' => true, 'sell' => false, 'total' => 5000, 'created_at' => now()->subMinute()]);
        Receipt::factory()->create(['shop_id' => $b->id, 'active' => false, 'sell' => true, 'total' => 5000, 'created_at' => now()->subMinute()]);

        $html = $this->actingAs($user)->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee(__('Shops: share of sales'))
            ->getContent();

        $this->assertStringContainsString('75%', $html);   // Alpha 300 of 400
        $this->assertStringContainsString('25%', $html);   // Beta 100 of 400
        $this->assertSame(2, substr_count($html, 'stroke-width="50"')); // one pie slice per shop
    }
}
