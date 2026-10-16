<?php

namespace Tests\Feature;

use App\Livewire\Ordering\Index;
use App\Livewire\Settings\OrderRules as OrderRulesPage;
use App\Models\OrderRule;
use App\Models\User;
use App\Services\ForecastService;
use App\Services\OrderRecommendations;
use App\Services\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrderWorld;
use Tests\TestCase;

/** Order rules: inheritance, their effect on the forecast and the Ordering page, and the admin-only settings page. */
class OrderRulesTest extends TestCase
{
    use BuildsOrderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');   // the assertions read the English texts
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownWorld();
        parent::tearDown();
    }

    public function test_a_shop_inherits_what_it_does_not_override(): void
    {
        $this->defaultRule(['history_days' => 21, 'horizon_days' => 5, 'run_time' => '07:30', 'weekdays' => [1, 2]]);
        OrderRule::query()->create(['shop_id' => $this->a->id, 'history_days' => 14, 'weekdays' => []]);

        $e = OrderRule::effective([$this->a->id, $this->b->id]);

        $this->assertSame([14, 5, '07:30', true], [$e[$this->a->id]['history_days'], $e[$this->a->id]['horizon_days'], $e[$this->a->id]['run_time'], $e[$this->a->id]['enabled']]);
        $this->assertSame([], $e[$this->a->id]['weekdays']);               // an own empty list = every day, not inherited
        $this->assertSame([21, 5, '07:30', true], [$e[$this->b->id]['history_days'], $e[$this->b->id]['horizon_days'], $e[$this->b->id]['run_time'], $e[$this->b->id]['enabled']]);
        $this->assertSame([1, 2], $e[$this->b->id]['weekdays']);
        $this->assertNull($e[$this->b->id]['rule_id']);
    }

    public function test_without_any_row_the_built_in_defaults_apply_and_nothing_runs(): void
    {
        $e = OrderRule::effective([$this->a->id])[$this->a->id];

        $this->assertFalse($e['enabled']);
        $this->assertSame(28, $e['history_days']);
        $this->assertSame(3, $e['horizon_days']);
        $this->assertSame('08:00', $e['run_time']);
        $this->assertSame([], $e['weekdays']);
        $this->assertSame(3, ForecastService::horizon());
    }

    /** Out of stock (snapshot 0) during the last 12 days, sales 6 a day on the 16 days before. */
    private function gapItem(\App\Models\Shop $shop): \App\Models\Item
    {
        $x = $this->item('Gap', $shop, 0, 1, 50);
        for ($i = 1; $i <= 12; $i++) {
            \App\Models\Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $x->id, 'qty' => 0, 'stock_date' => now()->subDays($i)->toDateString()]);
        }
        for ($i = 13; $i <= 28; $i++) {
            $this->sell($shop, $x, 6, now()->subDays($i)->setTime(11, 0)->toDateTimeString());
        }

        return $x;
    }

    public function test_the_history_window_changes_the_forecast_per_shop(): void
    {
        $this->gapItem($this->a);
        $this->gapItem($this->b);
        OrderRule::query()->create(['shop_id' => $this->a->id, 'history_days' => 14]);

        $rows = collect(app(ForecastService::class)->build()['rows'])->keyBy('shop_id');

        // 28 days: the 16 days before the gap are usable and give the speed; 14 days: only 2 usable days, rules only.
        $this->assertSame(16, $rows[$this->b->id]['usable_days']);
        $this->assertEqualsWithDelta(6.0, $rows[$this->b->id]['speed'], 1e-9);
        $this->assertSame('high', $rows[$this->b->id]['confidence']);
        $this->assertSame(2, $rows[$this->a->id]['usable_days']);
        $this->assertNull($rows[$this->a->id]['speed']);
        $this->assertSame('none', $rows[$this->a->id]['confidence']);
    }

    public function test_confidence_is_low_below_ten_usable_days(): void
    {
        $nine = $this->item('Nine days', $this->a, 1, 1, 10, '2026-09-28');    // 9 complete days: Sep 28 .. Oct 6
        $ten = $this->item('Ten days', $this->a, 1, 1, 10, '2026-09-27');
        $this->sellDaily($this->a, $nine, 1, 9);
        $this->sellDaily($this->a, $ten, 1, 10);

        $rows = collect(app(ForecastService::class)->build()['rows'])->keyBy('item');

        $this->assertSame('low', $rows['Nine days']['confidence']);
        $this->assertSame('ok', $rows['Ten days']['confidence']);
    }

    public function test_the_speed_ladder_is_cut_at_the_history_window(): void
    {
        // Out of stock (censored) on 5 of the last 7 days: the 7-day window has 2 usable days only, so the speed
        // needs the 14-day step. With a 14-day window the step exists too; ensure it is still found.
        $x = $this->item('Gappy', $this->a, 8, 2, 50, '2026-10-01');
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'] as $d) {
            \App\Models\Stock::query()->where('item_id', $x->id)->where('stock_date', $d)->delete();
            \App\Models\Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $x->id, 'qty' => 0, 'stock_date' => $d]);
        }
        $this->sellDaily($this->a, $x, 4, 28);
        OrderRule::query()->create(['shop_id' => $this->a->id, 'history_days' => 14]);

        $row = collect(app(ForecastService::class)->build()['rows'])->firstWhere('item', 'Gappy');

        $this->assertNotNull($row);
        $this->assertSame(5, $row['censored_days']);
        $this->assertEqualsWithDelta(4.0, $row['speed'], 1e-9);
    }

    public function test_the_plan_uses_each_shops_horizon_and_the_page_can_override_it(): void
    {
        foreach ([$this->a, $this->b] as $shop) {
            $x = $this->item('Cola '.$shop->name, $shop, 4, 5, 60, '2026-09-01');
            $this->sellDaily($shop, $x, 3);
            $this->warehouseHas($x, 1000);
        }
        $this->defaultRule(['horizon_days' => 3]);
        OrderRule::query()->create(['shop_id' => $this->a->id, 'horizon_days' => 6]);

        $forecast = app(ForecastService::class)->build(null, [$this->wh->id]);
        $plan = app(OrderRecommendations::class)->plan($forecast, null, $this->wh->id);
        $byShop = collect($plan['shops'])->keyBy('id');

        $this->assertSame(6, $byShop[$this->a->id]['horizon']);
        $this->assertSame(3, $byShop[$this->b->id]['horizon']);
        $this->assertSame(3, $plan['horizon']);
        $needA6 = $byShop[$this->a->id]['rows'][0]['need'];
        $needB3 = $byShop[$this->b->id]['rows'][0]['need'];
        $this->assertGreaterThan($needB3, $needA6);

        // The page: shop rules by default, a manual horizon applies to every shop, and back.
        $admin = User::factory()->create();
        $component = Livewire::actingAs($admin)->test(Index::class)->call("generate")->call('selectShop', $this->a->id);
        $this->assertSame(6, $component->viewData('horizon'));            // shows the selected shop's own horizon
        $component->call('setHorizon', 2);
        $this->assertSame(2, $component->viewData('horizon'));
        $this->assertTrue($component->viewData('custom'));
        $component->call('useShopRules');
        $this->assertSame(6, $component->viewData('horizon'));

        // "Prepare order" uses the shop's rule horizon, so it agrees with the automatic draft.
        $component->call('prepareOrder', $this->a->id);
        $this->assertSame(6, (int) \App\Models\Order::query()->where('shop_id', $this->a->id)->value('horizon_days'));
    }

    public function test_a_rule_change_invalidates_the_cached_forecast(): void
    {
        $this->gapItem($this->a);
        $admin = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(Index::class)->call("generate");
        $before = $component->viewData('active')['rows'][0];
        $this->assertSame('high', $before['confidence']);

        OrderRule::query()->create(['shop_id' => $this->a->id, 'history_days' => 14]);
        $component->call('selectShop', $this->a->id);
        $after = $component->viewData('active')['rows'][0];

        $this->assertSame('none', $after['confidence']);
    }

    public function test_the_settings_page_is_admin_only(): void
    {
        $this->get('/settings/order-rules')->assertRedirect('/login');
        $this->actingAs(User::factory()->operator()->create())->get('/settings/order-rules')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create())->get('/settings/order-rules')->assertOk()->assertSee('Order rules');

        Livewire::actingAs(User::factory()->operator()->create())->test(OrderRulesPage::class)->assertForbidden();
    }

    public function test_the_default_and_a_shop_override_are_saved_and_validated(): void
    {
        $admin = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(OrderRulesPage::class)
            ->set('defHistory', '30')->set('defHorizon', '9')->set('defRunTime', '25:99')
            ->call('saveDefault')
            ->assertHasErrors(['defHistory', 'defHorizon', 'defRunTime']);
        $this->assertNull(OrderRule::defaultRow());

        $component->set('defEnabled', true)->set('defHistory', '21')->set('defHorizon', '4')->set('defRunTime', '06:45')
            ->set('defWeekdays', ['1', '3'])->call('saveDefault')->assertHasNoErrors();
        $row = OrderRule::defaultRow();
        $this->assertSame([true, 21, 4, '06:45', [1, 3]], [$row->enabled, $row->history_days, $row->horizon_days, $row->run_time, $row->weekdays]);

        $component->call('edit', $this->a->id)
            ->set('history', '14')->set('weekdayMode', 'all')
            ->call('saveShop')->assertHasNoErrors();
        $own = OrderRule::query()->where('shop_id', $this->a->id)->first();
        $this->assertSame([null, 14, null, []], [$own->enabled, $own->history_days, $own->horizon_days, $own->weekdays]);

        $e = OrderRule::effective([$this->a->id])[$this->a->id];
        $this->assertSame([true, 14, 4, '06:45', []], [$e['enabled'], $e['history_days'], $e['horizon_days'], $e['run_time'], $e['weekdays']]);

        // The warehouse is not a shop to replenish.
        Livewire::actingAs($admin)->test(OrderRulesPage::class)->call('edit', $this->wh->id)->assertNotFound();
        // Reset gives every column back to the default but keeps the row (its last run state).
        $component->call('resetShop', $this->a->id);
        $this->assertNull($own->fresh()->history_days);
    }

    public function test_the_test_message_goes_to_the_shops_responsible_users(): void
    {
        config(['services.telegram.bot_token' => 'TESTTOKEN']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $with = User::factory()->operator()->create(['name' => 'Aziz', 'telegram_chat_id' => '4242']);
        $without = User::factory()->operator()->create(['name' => 'Bekzod']);
        $with->shops()->attach($this->a->id);
        $without->shops()->attach($this->a->id);

        Livewire::actingAs(User::factory()->create())->test(OrderRulesPage::class)
            ->assertSee('Aziz')->assertSee('Bekzod')->assertSee('no Telegram')
            ->call('sendTest', $this->a->id)
            ->assertSet('testOk', true);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/botTESTTOKEN/sendMessage')
            && $request['chat_id'] === '4242' && str_contains($request['text'], 'Alpha'));
    }

    public function test_a_shop_without_a_responsible_user_with_telegram_shows_a_hint_and_the_page_has_no_chat_field(): void
    {
        config(['services.telegram.bot_token' => 'TESTTOKEN']);
        Http::fake();

        Livewire::actingAs(User::factory()->create())->test(OrderRulesPage::class)
            ->assertSee('No responsible user is assigned to this shop.')
            ->call('sendTest', $this->a->id)
            ->assertSet('testOk', false)
            ->assertSet('testMessage', 'This shop has no responsible user with a Telegram chat id.')
            ->assertDontSeeHtml('wire:model="chat"')
            ->assertDontSeeHtml('wire:model="defChat"');

        Http::assertNothingSent();
    }

    public function test_a_telegram_error_never_contains_the_bot_token(): void
    {
        config(['services.telegram.bot_token' => 'SECRET:TOKEN']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error for https://api.telegram.org/botSECRET:TOKEN/sendMessage'));

        $result = app(Telegram::class)->send('1', 'hi');

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('SECRET:TOKEN', $result['error']);
    }
}
