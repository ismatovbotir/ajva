<?php

namespace Tests\Feature;

use App\Livewire\Monitor;
use App\Livewire\Settings\PublicMonitor;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\User;
use App\Support\MonitorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PublicMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(bool $profit = false): string
    {
        $settings = app(MonitorSettings::class);
        $token = $settings->generateToken();
        $settings->setShowProfit($profit);

        return $token;
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
        $token = $this->enable();

        $response = $this->get('/monitor/'.$token)
            ->assertOk()
            ->assertSeeLivewire(Monitor::class)
            ->assertSee('wire:poll.30s', false)
            ->assertDontSee('/logout', false)
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">', false);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_wrong_unknown_or_disabled_tokens_are_a_plain_404(): void
    {
        $this->get('/monitor/anything')->assertNotFound();   // nothing configured

        $token = $this->enable();
        $this->get('/monitor/'.str_replace('a', 'b', $token).'x')->assertNotFound();

        app(MonitorSettings::class)->setEnabled(false);
        $this->get('/monitor/'.$token)->assertNotFound();
    }

    public function test_regenerating_invalidates_the_old_link_including_livewire_updates(): void
    {
        $old = $this->enable();
        $component = Livewire::test(Monitor::class, ['token' => $old]);
        $component->call('$refresh')->assertOk();   // still fine

        $new = app(MonitorSettings::class)->generateToken();
        $this->assertNotSame($old, $new);

        $this->get('/monitor/'.$old)->assertNotFound();
        $this->get('/monitor/'.$new)->assertOk();

        $component->call('$refresh')->assertNotFound();   // the open screen's next poll
    }

    public function test_disabling_stops_an_open_screens_polling(): void
    {
        $token = $this->enable();
        $component = Livewire::test(Monitor::class, ['token' => $token]);
        app(MonitorSettings::class)->setEnabled(false);

        $component->call('$refresh')->assertNotFound();
    }

    public function test_the_token_cannot_be_changed_from_the_browser(): void
    {
        $token = $this->enable();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(Monitor::class, ['token' => $token])->set('publicToken', 'other');
    }

    public function test_profit_is_hidden_on_the_public_screen_by_default_and_shown_when_switched_on(): void
    {
        $this->seedSale();
        $token = $this->enable(false);

        $hidden = $this->get('/monitor/'.$token)->assertOk()->getContent();
        foreach ([__('Profit'), __('Margin'), 'Cost missing', __('Cost missing for :count items — :percent% of revenue is not covered.', ['count' => 0, 'percent' => 0])] as $label) {
            $this->assertStringNotContainsString($label, $hidden);
        }
        $this->assertStringContainsString(__('Peak hour'), $hidden);
        $this->assertStringContainsString('Cola', $hidden);

        $component = Livewire::test(Monitor::class, ['token' => $token]);
        $this->assertArrayNotHasKey('profit', $component->viewData('trend')[0]);
        $this->assertArrayNotHasKey('profit', $component->viewData('topItems')['all'][0]);

        app(MonitorSettings::class)->setShowProfit(true);
        $shown = $this->get('/monitor/'.$token)->assertOk()->getContent();
        $this->assertStringContainsString(__('Profit'), $shown);
        $this->assertStringContainsString(__('Margin'), $shown);
        $this->assertStringNotContainsString(__('Peak hour'), $shown);
    }

    public function test_authenticated_monitor_still_works_redirects_guests_and_always_shows_profit(): void
    {
        $this->get('/monitor')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/monitor')
            ->assertOk()
            ->assertSee(__('Profit'))
            ->assertSee('href="'.route('dashboard').'"', false);

        $this->actingAs(User::factory()->operator()->create())->get('/monitor')->assertOk();
        $this->actingAs(User::factory()->monitor()->create())->get('/monitor')->assertOk();
    }

    public function test_settings_page_is_admin_only_and_manages_the_link(): void
    {
        $this->get('/settings/monitor')->assertRedirect('/login');
        $this->actingAs(User::factory()->operator()->create())->get('/settings/monitor')->assertForbidden();

        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/settings/monitor')->assertOk()->assertSee(__('Generate link'));

        $settings = app(MonitorSettings::class);
        Livewire::actingAs($admin)->test(PublicMonitor::class)->call('generateToken');
        $first = $settings->token();
        $this->assertNotNull($first);
        $this->assertTrue($settings->enabled());          // first link switches it on
        $this->assertFalse($settings->showProfit());      // profit off by default

        Livewire::actingAs($admin)->test(PublicMonitor::class)
            ->assertSee(route('monitor.public', $first))
            ->call('generateToken')
            ->call('toggleProfit')
            ->call('toggleEnabled');

        $this->assertNotSame($first, $settings->token());
        $this->assertTrue($settings->showProfit());
        $this->assertFalse($settings->enabled());
        $this->assertFalse($settings->accepts($settings->token()));
    }

    public function test_the_settings_page_has_a_button_that_opens_the_link_in_a_new_window(): void
    {
        $admin = User::factory()->create();
        $settings = app(MonitorSettings::class);
        $settings->generateToken();
        $link = rtrim((string) config('app.url'), '/').'/monitor/'.$settings->token();

        $html = Livewire::actingAs($admin)->test(PublicMonitor::class)->html();

        $this->assertMatchesRegularExpression(
            '~<a href="'.preg_quote($link, '~').'"[^>]*target="_blank"[^>]*rel="noopener noreferrer"~s',
            $html
        );
        $this->assertStringContainsString(__('Open in new window'), $html);
    }

    public function test_the_link_is_built_from_app_url_and_warns_when_it_is_local(): void
    {
        $admin = User::factory()->create();
        $settings = app(MonitorSettings::class);
        $settings->generateToken();
        $token = $settings->token();

        // A real public APP_URL (with a trailing slash) gives exactly APP_URL + /monitor/{token}.
        config(['app.url' => 'https://crm.example.uz/']);
        Livewire::actingAs($admin)->test(PublicMonitor::class)
            ->assertSee('https://crm.example.uz/monitor/'.$token)
            ->assertDontSee(__('APP_URL points to localhost, so this link will not work on the TV. Set APP_URL to the public address of this server in .env and clear the config cache.'));

        // A localhost APP_URL still builds the link from it, plus a visible warning.
        config(['app.url' => 'http://localhost']);
        Livewire::actingAs($admin)->test(PublicMonitor::class)
            ->assertSee('http://localhost/monitor/'.$token)
            ->assertSee(__('APP_URL points to localhost, so this link will not work on the TV. Set APP_URL to the public address of this server in .env and clear the config cache.'));
    }

    public function test_monitor_shows_each_shops_share_of_sales_as_a_pie(): void
    {
        $user = User::factory()->create();
        $a = Shop::factory()->create(['name' => 'Shop Alpha']);
        $b = Shop::factory()->create(['name' => 'Shop Beta']);
        foreach ([[$a, 300], [$b, 100]] as [$shop, $total]) {
            Receipt::factory()->create(['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => $total, 'created_at' => now()->subMinute()]);
        }
        // Refunds and cancelled receipts must not count towards the share.
        Receipt::factory()->create(['shop_id' => $b->id, 'active' => true, 'sell' => false, 'total' => 5000, 'created_at' => now()->subMinute()]);
        Receipt::factory()->create(['shop_id' => $b->id, 'active' => false, 'sell' => true, 'total' => 5000, 'created_at' => now()->subMinute()]);

        $html = $this->actingAs($user)->get('/monitor')->assertOk()
            ->assertSee(__('Shops: share of sales'))
            ->getContent();

        $this->assertStringContainsString('75%', $html);   // Alpha 300 of 400
        $this->assertStringContainsString('25%', $html);   // Beta 100 of 400
        $this->assertSame(2, substr_count($html, 'stroke-width="50"')); // one pie slice per shop
    }
}
