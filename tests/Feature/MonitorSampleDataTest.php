<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Monitor\Grid;
use App\Monitor\MonitorData;
use App\Monitor\Render;
use App\Monitor\WidgetCatalog;
use App\Support\MonitorScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * The constructor canvas draws generated SAMPLE data through the same WidgetPayloads class as the real screen: every catalog
 * widget must come out in the shape its diagram component needs, deterministically, and with no database access at all.
 */
class MonitorSampleDataTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 09:12:34');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sampleScope(): MonitorScope
    {
        return new MonitorScope(new \App\Models\Monitor, null, true, false, now());
    }

    /** @return array<string, mixed> */
    private function one(string $key, string $diagram, array $options = [], int $h = 4): array
    {
        $def = WidgetCatalog::find($key);
        $layout = [['id' => '11111111-1111-4111-8111-111111111111', 'key' => $key, 'diagram' => $diagram, 'x' => 0, 'y' => 0, 'w' => max($def->w, 4), 'h' => max($def->h, $h), 'options' => $options]];

        return MonitorData::build($this->sampleScope(), $layout, true)['widgets'][0];
    }

    public function test_every_widget_and_diagram_of_the_catalog_has_sample_data_in_the_shape_its_component_draws(): void
    {
        foreach (WidgetCatalog::all() as $def) {
            foreach ($def->diagrams as $diagram) {
                $w = $this->one($def->key, $diagram->value);
                $label = $def->key.' / '.$diagram->value;

                $this->assertSame('ok', $w['state'], $label.' has sample data');
                // The documented fallbacks (a line of < 3 points, a donut of > 7 shops, a gauge without a rate) must not trigger.
                $this->assertSame($diagram->value, Render::diagram($w), $label.' is drawn as chosen');

                match ($def->shape) {
                    'single' => $this->assertArrayHasKey('value', $w, $label),
                    'series' => $this->assertTrue(isset($w['labels'], $w['lines']) && count($w['labels']) >= 3 && count($w['lines'][0]['values']) === count($w['labels']), $label),
                    'by_shop', 'by_item' => $this->assertTrue(isset($w['columns'], $w['items']) && $w['items'] !== [] && count($w['items'][0]['cells'] ?? []) === count($w['columns']), $label),
                    'parts' => $this->assertNotEmpty($w['parts'], $label),
                    'events' => $this->assertNotEmpty($w['events'], $label),
                };
                if ($diagram->value === 'sparkline') {
                    $this->assertGreaterThanOrEqual(3, count($w['spark']), $label);
                }
                if ($diagram->value === 'gauge') {
                    $this->assertArrayHasKey('gauge', $w, $label);
                }
                if ($def->comparison && $def->shape === 'single') {
                    $this->assertArrayHasKey('compare', $w, $label);
                }

                // ...and the real Blade component draws it without an error.
                $html = Blade::render('<x-monitor.widget :w="$w" />', ['w' => $w]);
                $this->assertStringContainsString('data-widget="'.$def->key.'"', $html, $label);
            }
        }
    }

    public function test_the_sample_pass_runs_no_database_query_and_ignores_real_data(): void
    {
        $shop = Shop::factory()->create(['name' => 'Real Secret Shop']);
        $this->sale($shop, '2026-10-07 08:00:00', 123456);
        $layout = [];
        foreach (WidgetCatalog::all() as $def) {
            $layout = Grid::add($layout, $def, (string) \Illuminate\Support\Str::uuid());
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = MonitorData::build($this->sampleScope(), $layout, true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'the canvas must not touch the database');
        $this->assertCount(count(WidgetCatalog::all()), $result['widgets']);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Real Secret Shop', $json);
        $this->assertStringNotContainsString('123 456', $json);
        $this->assertStringContainsString(__('Shop').' 1', $json);
    }

    public function test_the_sample_is_deterministic_so_the_canvas_does_not_flicker(): void
    {
        $layout = Grid::add([], WidgetCatalog::find('shop_leaderboard'), '22222222-2222-4222-8222-222222222222');
        $layout = Grid::add($layout, WidgetCatalog::find('hourly_sales'), '33333333-3333-4333-8333-333333333333');

        $first = json_encode(MonitorData::build($this->sampleScope(), $layout, true));
        Carbon::setTestNow('2026-10-07 09:59:59');
        $second = json_encode(MonitorData::build($this->sampleScope(), $layout, true));

        $this->assertSame($first, $second);
    }

    public function test_rows_follow_top_n_and_the_sample_shows_every_colour_state(): void
    {
        foreach ([3, 7, 15] as $n) {
            $this->assertCount($n, $this->one('top_items', 'table', ['top_n' => $n])['items'], 'top_items top_n='.$n);
            $this->assertCount($n, $this->one('wh_pick_list', 'table', ['top_n' => $n])['items'], 'wh_pick_list top_n='.$n);
        }

        $tones = collect($this->one('shop_leaderboard', 'table')['items'])->pluck('tones')->flatten()->filter()->unique()->all();
        foreach (['ok', 'warn', 'bad'] as $state) {
            $this->assertContains($state, $tones, 'a sample delta in state '.$state);
        }

        $sales = $this->one('sales_revenue', 'kpi');
        $this->assertNotSame('none', $sales['status']);
        $this->assertNotEmpty($sales['compare']['a_text']);
    }

    public function test_the_sample_pass_needs_no_profit_permission_to_exist_but_is_never_real_cost_data(): void
    {
        // Profit widgets show invented figures on the canvas (nothing is read), real ones only in Preview with permission.
        $w = $this->one('gross_profit', 'kpi');
        $this->assertSame('ok', $w['state']);
        $this->assertSame(0, DB::table('item_prices')->count());
    }
}
