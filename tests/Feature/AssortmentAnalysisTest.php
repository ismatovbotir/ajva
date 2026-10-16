<?php

namespace Tests\Feature;

use App\Livewire\Assortment\Index as AssortmentPage;
use App\Mcp\InvalidArguments;
use App\Mcp\Tools\AssortmentAnalysisReport;
use App\Models\Item;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\AssortmentAnalysis;
use App\Support\LocalAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * ABC x XYZ, dead / slow stock and A items out of stock, against hand-computed fixtures.
 * Window: to = 2026-10-06, 8 full weeks => from = 2026-08-12 (week k starts at from + 7k).
 */
class AssortmentAnalysisTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    private Item $steady;

    private Item $alternating;

    private Item $erratic;

    private Item $small;

    private Item $dead;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
        $this->steady = Item::factory()->create(['name' => 'Steady']);
        $this->alternating = Item::factory()->create(['name' => 'Alternating']);
        $this->erratic = Item::factory()->create(['name' => 'Erratic']);
        $this->small = Item::factory()->create(['name' => 'Small']);
        $this->dead = Item::factory()->create(['name' => 'Dead']);

        $from = Carbon::parse('2026-08-12');
        for ($k = 0; $k < 8; $k++) {
            $day = $from->copy()->addDays(7 * $k + 2)->format('Y-m-d').' 10:00:00';
            $this->sale($this->a, $day, 1000, [], 1, $this->steady, 10);            // 8000, qty 10 / week
            $this->sale($this->a, $day, $k % 2 === 0 ? 100 : 200, [], 1, $this->alternating, $k % 2 === 0 ? 5 : 15); // 1200
            $this->sale($this->a, $day, 50, [], 1, $this->small, 1);                // 400, qty 1 / week
        }
        $this->sale($this->a, $from->copy()->addDays(23)->format('Y-m-d').' 11:00:00', 400, [], 1, $this->erratic, 80); // 400 in one week
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function build(?array $shops = null, bool $profit = false, string $from = '2026-08-12', string $to = '2026-10-06'): array
    {
        return app(AssortmentAnalysis::class)->build(Carbon::parse($from), Carbon::parse($to), $shops ?? [$this->a->id, $this->b->id], $profit);
    }

    private function top(array $report): array
    {
        return collect($report['top'])->keyBy('name')->all();
    }

    public function test_abc_follows_the_cumulative_revenue_share(): void
    {
        $r = $this->build();
        $top = $this->top($r);

        // Revenue 8000 / 1200 / 400 / 400 of 10 000: 80 % (A), 92 % (B), then C and C.
        $this->assertSame('A', $top['Steady']['abc']);
        $this->assertSame('B', $top['Alternating']['abc']);
        $this->assertSame('C', $top['Erratic']['abc']);
        $this->assertSame('C', $top['Small']['abc']);
        $this->assertEqualsWithDelta(80.0, $top['Steady']['share'], 0.001);
        $this->assertSame(10000.0, $r['kpi']['revenue']);
        $this->assertSame(['A' => 1, 'B' => 1, 'C' => 2], $r['kpi']['class_count']);
        $this->assertSame(4, $r['kpi']['items']);
        $this->assertEqualsWithDelta(25.0, $r['kpi']['a_items_share'], 0.001);
    }

    public function test_xyz_uses_the_weekly_coefficient_of_variation(): void
    {
        $r = $this->build();
        $top = $this->top($r);

        $this->assertTrue($r['window']['xyz']);
        $this->assertSame(8, $r['window']['weeks']);
        $this->assertSame('X', $top['Steady']['xyz'], 'the same quantity every week: CV 0');
        $this->assertSame('Y', $top['Alternating']['xyz'], '5 / 15 alternating: mean 10, sd 5, CV exactly 0.5');
        $this->assertSame('Z', $top['Erratic']['xyz'], '80 in one week and 0 in seven: CV 2.6');
        $this->assertSame('X', $top['Small']['xyz']);

        $m = $r['matrix'];
        $this->assertSame(1, $m['A']['X']['items']);
        $this->assertEqualsWithDelta(80.0, $m['A']['X']['share'], 0.001);
        $this->assertSame(1, $m['B']['Y']['items']);
        $this->assertSame(1, $m['C']['Z']['items']);
        $this->assertSame(1, $m['C']['X']['items']);
        $this->assertEqualsWithDelta(100.0, collect($m)->flatten(1)->sum('share'), 0.001, 'the matrix covers all revenue');
        $this->assertSame(1, $r['kpi']['cz_items']);
        $this->assertEqualsWithDelta(4.0, $r['kpi']['cz_share_revenue'], 0.001);
    }

    public function test_xyz_is_not_computed_for_fewer_than_eight_weeks_and_whole_weeks_are_used(): void
    {
        $short = $this->build(null, false, '2026-09-09', '2026-10-06'); // 28 days = 4 weeks
        $this->assertFalse($short['window']['xyz']);
        $this->assertNull($short['matrix']);
        $this->assertNull($short['kpi']['cz_items']);
        $this->assertSame(4, $short['window']['weeks']);

        // 90 days requested: 12 whole weeks = 84 days, the 6 oldest days are dropped.
        $long = $this->build(null, false, '2026-07-09', '2026-10-06');
        $this->assertSame(90, $long['window']['requested_days']);
        $this->assertSame([84, 12, '2026-07-15'], [$long['window']['days'], $long['window']['weeks'], $long['window']['from']]);
    }

    public function test_refunds_cancelled_receipts_and_storno_lines_never_count(): void
    {
        $day = '2026-09-20 10:00:00';
        $this->sale($this->a, $day, 5000, ['sell' => false], 1, $this->steady, 50);   // refund
        $this->sale($this->a, $day, 5000, ['active' => false], 1, $this->steady, 50); // cancelled
        $receipt = $this->sale($this->a, $day, 0, [], 0);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $this->steady->id, 'qty' => 99, 'total' => 9999, 'storno' => true]);

        $this->assertSame(10000.0, $this->build()['kpi']['revenue']);
    }

    public function test_dead_slow_and_a_items_out_of_stock(): void
    {
        $this->stock($this->steady, $this->a, 0);       // the top item is out in Alpha
        $this->stock($this->steady, $this->b, 30);
        $this->stock($this->small, $this->a, 100);      // 8 sold in 56 days => 700 days of cover
        $this->stock($this->dead, $this->a, 20);        // never sold
        $this->stock($this->alternating, $this->a, 5);  // 80 sold: cover 3.5 days, fine

        $r = $this->build();

        $this->assertSame(1, $r['kpi']['a_at_risk']);
        $risk = $r['at_risk'][0];
        $this->assertSame(['Steady', 1, 2, 30.0], [$risk['name'], $risk['out_shops'], $risk['shops'], $risk['on_hand']]);

        $this->assertSame(1, $r['kpi']['dead']);
        $this->assertSame('Dead', $r['dead'][0]['name']);
        $this->assertSame(20.0, $r['dead'][0]['on_hand']);

        $this->assertSame(1, $r['kpi']['slow']);
        $this->assertSame('Small', $r['slow'][0]['name']);
        $this->assertEqualsWithDelta(100 / (8 / 56), $r['slow'][0]['cover'], 0.01);
        $this->assertSame('2026-10-07', $r['kpi']['stock_as_of']);
        $this->assertFalse($r['kpi']['stock_stale']);
    }

    public function test_stock_history_is_read_through_the_latest_snapshot(): void
    {
        Stock::factory()->create(['item_id' => $this->dead->id, 'shop_id' => $this->a->id, 'qty' => 500, 'stock_date' => '2026-10-01']);
        Stock::factory()->create(['item_id' => $this->dead->id, 'shop_id' => $this->a->id, 'qty' => 7, 'stock_date' => '2026-10-05']);

        $r = $this->build();

        $this->assertSame(7.0, $r['dead'][0]['on_hand'], 'history rows are never summed');
        $this->assertFalse($r['kpi']['stock_stale'], '2026-10-05 is within 3 days of today');

        Stock::query()->where('qty', 500)->delete();
        Stock::query()->where('qty', 7)->update(['stock_date' => '2026-10-01']);
        $this->assertTrue($this->build()['kpi']['stock_stale'], 'a snapshot older than 3 days is flagged');
    }

    public function test_shop_scope_limits_every_figure(): void
    {
        $this->sale($this->b, '2026-09-20 10:00:00', 7777, [], 1, $this->dead, 3);

        $onlyA = $this->build([$this->a->id]);
        $this->assertSame(4, $onlyA['kpi']['items']);
        $this->assertSame(10000.0, $onlyA['kpi']['revenue']);

        $onlyB = $this->build([$this->b->id]);
        $this->assertSame(1, $onlyB['kpi']['items']);
        $this->assertSame(7777.0, $onlyB['kpi']['revenue']);

        $this->assertSame(0, $this->build([])['kpi']['items']);
    }

    public function test_cost_figures_only_exist_with_profit_and_missing_cost_is_not_zero(): void
    {
        $other = Item::factory()->create(['name' => 'No cost']);
        $this->stock($this->dead, $this->a, 20);
        $this->stock($other, $this->a, 10);
        $this->cost($this->dead, 12);

        $without = $this->build();
        $this->assertNull($without['kpi']['stock_value']);
        $this->assertFalse($without['profit']);
        $this->assertNull($without['dead'][0]['value']);

        $with = $this->build(null, true);
        $this->assertSame(20 * 12.0, $with['kpi']['stock_value']['dead']);
        $this->assertSame(1, $with['kpi']['stock_value']['dead_without_cost']);
        $rows = collect($with['dead'])->keyBy('name');
        $this->assertSame(240.0, $rows['Dead']['value']);
        $this->assertNull($rows['No cost']['value']);
    }

    public function test_the_conclusions_name_the_action(): void
    {
        $this->stock($this->steady, $this->a, 0);
        $this->stock($this->dead, $this->a, 20);
        $report = $this->build();
        $notes = collect(LocalAdvisor::notes('assortment_analysis', ['report' => $report]));

        $this->assertGreaterThanOrEqual(2, $notes->where('level', 'warn')->count(), 'A item out of stock and dead stock are actions');
        $this->assertTrue($notes->contains(fn ($n) => $n['level'] === 'warn' && str_contains($n['text'], '56')), 'dead stock names the period');

        $short = $this->build(null, false, '2026-09-09', '2026-10-06');
        $text = collect(LocalAdvisor::notes('assortment_analysis', ['report' => $short]))->pluck('text')->implode(' ');
        $this->assertStringContainsString('8', $text);
        $this->assertSame([], LocalAdvisor::notes('assortment_analysis', ['report' => null]));
    }

    // ---------------------------------------------------------------- page and tool

    public function test_the_page_is_for_admins_and_operators_and_defaults_to_twelve_weeks(): void
    {
        $this->get(route('assortment.index'))->assertRedirect();
        $this->actingAs(User::factory()->monitor()->create())->get(route('assortment.index'))->assertRedirect();

        $this->actingAs(User::factory()->create())->get(route('assortment.index'))->assertOk()->assertSee(__('Assortment analysis'));

        Livewire::actingAs(User::factory()->create())->test(AssortmentPage::class)
            ->assertSet('dateTo', '2026-10-06')->assertSet('dateFrom', '2026-07-15');
    }

    public function test_the_page_renders_the_matrix_conclusions_and_validates_the_period(): void
    {
        $this->stock($this->steady, $this->a, 0);
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(AssortmentPage::class)
            ->set('dateFrom', '2026-08-12')->set('dateTo', '2026-10-06')->call('generate')
            ->assertSee(__('ABC x XYZ matrix'))->assertSee(__('Conclusions'))->assertSee('Steady')
            ->assertSee(__('Class A items out of stock'));

        Livewire::actingAs($admin)->test(AssortmentPage::class)
            ->set('dateFrom', '2026-01-01')->set('dateTo', '2026-10-06')->call('generate')->assertHasErrors('dateTo');
        Livewire::actingAs($admin)->test(AssortmentPage::class)
            ->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-03')->call('generate')->assertHasErrors('dateTo');
    }

    public function test_an_operator_only_sees_their_shops_and_never_cost_figures(): void
    {
        $this->sale($this->b, '2026-09-20 10:00:00', 7777, [], 1, $this->dead, 3);
        $this->stock($this->small, $this->a, 100);
        $this->cost($this->small, 5);

        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$this->b->id]);

        $page = Livewire::actingAs($operator)->test(AssortmentPage::class)
            ->set('dateFrom', '2026-08-12')->set('dateTo', '2026-10-06')
            ->set('shopIds', [$this->a->id, $this->b->id]) // Alpha is not theirs
            ->call('generate')
            ->assertSee('Dead')->assertDontSee('Steady')->assertDontSee(__('Value at cost'));

        // The admin with profit sees the value column for the same period (separate cache entry).
        Livewire::actingAs(User::factory()->create())->test(AssortmentPage::class)
            ->set('dateFrom', '2026-08-12')->set('dateTo', '2026-10-06')->call('generate')
            ->assertSee(__('Value at cost'));
    }

    public function test_an_operator_without_shops_gets_the_hint_not_everything(): void
    {
        Livewire::actingAs(User::factory()->operator()->create())->test(AssortmentPage::class)
            ->assertSee(__('No shops are assigned to you. Ask an admin.'));
    }

    public function test_the_mcp_tool_returns_the_report_without_cost_and_validates_input(): void
    {
        $tool = new AssortmentAnalysisReport;
        $out = $tool->handle(['from' => '2026-08-12', 'to' => '2026-10-06']);

        $this->assertSame('assortment_analysis', $tool->name());
        $this->assertSame(4, $out['report']['kpi']['items']);
        $this->assertFalse($out['report']['profit']);
        $this->assertNull($out['report']['kpi']['stock_value']);

        $this->expectException(InvalidArguments::class);
        $tool->handle(['from' => '2026-10-01', 'to' => '2026-10-03']);
    }

    private function stock(Item $item, Shop $shop, float $qty): void
    {
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => $qty, 'stock_date' => now()->toDateString()]);
    }
}
