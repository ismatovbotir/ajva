<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Livewire\Settings\MonitorBuilder;
use App\Models\Monitor;
use App\Models\Shop;
use App\Models\User;
use App\Monitor\Grid;
use App\Monitor\LayoutValidator;
use App\Monitor\Presets;
use App\Monitor\WidgetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/** The layout editor: grid logic, catalog validation, limits, authorization and persistence. */
class CustomMonitorBuilderTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 15:00:00');
        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function builder(?Monitor $monitor = null)
    {
        return Livewire::actingAs($this->admin)->test(MonitorBuilder::class, ['monitor' => $monitor ?? $this->customMonitor()]);
    }

    /** @return array<string, array<string, mixed>> the working layout by widget key (first of each) */
    private function layoutOf($component): array
    {
        $out = [];
        foreach ($component->get('widgets') as $w) {
            $out[$w['key']] ??= $w;
        }

        return $out;
    }

    // ---------------------------------------------------------------- access

    public function test_only_admins_reach_the_builder(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);

        $this->actingAs(User::factory()->operator()->create())->get(route('settings.monitors.builder', $monitor))->assertForbidden();
        $this->actingAs(User::factory()->monitor()->create())->get(route('settings.monitors.builder', $monitor))->assertRedirect();
        $this->actingAs($this->admin)->get(route('settings.monitors.builder', $monitor))->assertOk()->assertSee(__('Monitor builder'));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('settings.monitors.builder', $this->customMonitor()))->assertRedirect();
    }

    public function test_an_operator_cannot_call_builder_actions_on_a_component_they_obtained(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);
        $component = $this->builder($monitor);
        $this->actingAs(User::factory()->operator()->create());

        $component->call('save')->assertForbidden();
        $this->assertSame(1, $monitor->fresh()->layout_version, 'nothing was stored');
    }

    public function test_only_custom_monitors_have_a_builder(): void
    {
        $executive = Monitor::factory()->create();

        $this->actingAs($this->admin)->get(route('settings.monitors.builder', $executive))->assertNotFound();
    }

    public function test_the_working_layout_cannot_be_written_from_the_browser(): void
    {
        $component = $this->builder();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('widgets', [['id' => 'x', 'key' => 'sales_revenue', 'diagram' => 'kpi', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2]]);
    }

    // ---------------------------------------------------------------- grid operations

    public function test_adding_a_widget_uses_the_catalog_defaults_and_selects_it(): void
    {
        $component = $this->builder()->call('addWidget', 'hourly_sales');

        $w = $component->get('widgets')[0];
        $this->assertSame('hourly_sales', $w['key']);
        $this->assertSame([8, 4, 0, 0], [$w['w'], $w['h'], $w['x'], $w['y']]);
        $this->assertSame('line', $w['diagram']);
        $component->assertSet('selectedId', $w['id'])->assertSet('dirty', true);

        $component->call('addWidget', 'does_not_exist');
        $this->assertCount(1, $component->get('widgets'));
    }

    public function test_move_clamps_to_the_grid(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue');
        $id = $component->get('widgets')[0]['id'];

        $component->call('move', $id, 50, 0);
        $this->assertSame(9, $component->get('widgets')[0]['x'], '12 columns - width 3');

        $component->call('move', $id, -4, -9);
        $w = $component->get('widgets')[0];
        $this->assertSame([0, 0], [$w['x'], $w['y']]);

        $component->call('move', 'not-a-widget', 1, 1);
        $this->assertCount(1, $component->get('widgets'));
    }

    public function test_colliding_widgets_are_pushed_down_and_the_layout_stays_compact(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue')->call('addWidget', 'receipt_count');
        [$first, $second] = $component->get('widgets');
        $this->assertSame([0, 2], [$second['x'], $second['y']], 'new widgets go below the others');

        // Drop the second one onto the first one: the one that was moved wins, the other is pushed down.
        $component->call('move', $second['id'], 0, 0);
        $byId = collect($component->get('widgets'))->keyBy('id');
        $this->assertSame([0, 0], [$byId[$second['id']]['x'], $byId[$second['id']]['y']]);
        $this->assertSame([0, 2], [$byId[$first['id']]['x'], $byId[$first['id']]['y']]);

        foreach ($component->get('widgets') as $a) {
            foreach ($component->get('widgets') as $b) {
                $this->assertTrue($a['id'] === $b['id'] || ! Grid::overlaps($a, $b));
            }
        }
    }

    public function test_widgets_fall_into_the_gap_a_move_leaves(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue')->call('addWidget', 'receipt_count');
        [$first, $second] = $component->get('widgets');

        $component->call('move', $first['id'], 6, 0);   // moves aside: the one below moves up
        $byId = collect($component->get('widgets'))->keyBy('id');

        $this->assertSame(0, $byId[$second['id']]['y']);
    }

    public function test_resize_clamps_to_catalog_limits_and_the_grid(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue');   // min 2x1, max 6x4
        $id = $component->get('widgets')[0]['id'];

        $component->call('resize', $id, 40, 40);
        $w = $component->get('widgets')[0];
        $this->assertSame([6, 4], [$w['w'], $w['h']]);

        $component->call('resize', $id, 0, 0);
        $w = $component->get('widgets')[0];
        $this->assertSame([2, 1], [$w['w'], $w['h']]);

        $component->call('move', $id, 11, 0);
        $component->call('resize', $id, 6, 2);
        $w = $component->get('widgets')[0];
        $this->assertSame(12, $w['x'] + $w['w'], 'never past the right edge');
    }

    public function test_resizing_a_widget_pushes_the_one_below_down(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue')->call('addWidget', 'receipt_count');
        [$first, $second] = $component->get('widgets');

        $component->call('resize', $first['id'], 3, 4);
        $byId = collect($component->get('widgets'))->keyBy('id');

        $this->assertSame(4, $byId[$second['id']]['y']);
    }

    public function test_keyboard_nudges_move_and_resize_by_one_cell(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue');
        $id = $component->get('widgets')[0]['id'];

        $component->call('nudge', $id, 1, 0)->call('nudge', $id, 1, 0);
        $this->assertSame(2, $component->get('widgets')[0]['x']);

        $component->call('nudge', $id, 1, 1, true);
        $w = $component->get('widgets')[0];
        $this->assertSame([4, 3], [$w['w'], $w['h']]);

        $component->call('nudge', $id, 99, 99);   // a nudge is one cell at most
        $this->assertSame(3, $component->get('widgets')[0]['x']);
    }

    public function test_the_numeric_panel_is_the_keyboard_alternative_and_is_clamped(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue');
        $id = $component->get('widgets')[0]['id'];

        $component->call('select', $id)
            ->set('panelX', 100)->set('panelY', 3)->set('panelW', 100)->set('panelH', -4)->set('panelTitle', '  <b>Daily</b> sales  ')
            ->call('applyPanel');

        $w = $component->get('widgets')[0];
        $this->assertSame(6, $w['w']);
        $this->assertSame(1, $w['h']);
        $this->assertSame(6, $w['x'], 'x is clamped so that the widget fits: 12 - 6');
        $this->assertLessThanOrEqual(3, $w['y']);
        $this->assertSame('Daily sales', $w['options']['title']);
    }

    public function test_the_panel_only_accepts_diagrams_the_metric_supports(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue');
        $id = $component->get('widgets')[0]['id'];

        $component->call('select', $id)->set('panelDiagram', 'heatmap')->call('applyPanel');
        $this->assertSame('kpi', $component->get('widgets')[0]['diagram']);

        $component->set('panelDiagram', 'sparkline')->call('applyPanel');
        $this->assertSame('sparkline', $component->get('widgets')[0]['diagram']);
    }

    public function test_top_n_is_clamped_to_fifteen(): void
    {
        $component = $this->builder()->call('addWidget', 'top_items');
        $id = $component->get('widgets')[0]['id'];

        $component->call('select', $id)->set('panelTopN', 99)->call('applyPanel');
        $this->assertSame(15, $component->get('widgets')[0]['options']['top_n']);

        $component->set('panelTopN', 0)->call('applyPanel');
        $this->assertSame(1, $component->get('widgets')[0]['options']['top_n']);
    }

    public function test_delete_duplicate_and_undo(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue');
        $id = $component->get('widgets')[0]['id'];

        $component->call('duplicate', $id);
        $widgets = $component->get('widgets');
        $this->assertCount(2, $widgets);
        $this->assertNotSame($widgets[0]['id'], $widgets[1]['id']);
        $this->assertSame('sales_revenue', $widgets[1]['key']);

        $component->call('remove', $widgets[1]['id']);
        $this->assertCount(1, $component->get('widgets'));
        $component->assertSet('selectedId', null);

        $component->call('undo');
        $this->assertCount(2, $component->get('widgets'), 'undo brings the deleted widget back');

        $component->call('undo')->call('undo');
        $this->assertCount(0, $component->get('widgets'));
        $component->call('undo');   // nothing left to undo: no error
        $this->assertCount(0, $component->get('widgets'));
    }

    // ---------------------------------------------------------------- limits

    public function test_at_most_sixteen_widgets(): void
    {
        $component = $this->builder();
        foreach (range(1, 20) as $i) {
            $component->call('addWidget', 'basket_size');
        }

        $this->assertCount(16, $component->get('widgets'));
        $component->assertSee(__('A monitor can have at most :max widgets.', ['max' => 16]));
    }

    public function test_at_most_four_expensive_widgets(): void
    {
        $component = $this->builder();
        foreach (['top_items', 'gross_profit', 'profit_by_shop', 'wh_pick_list', 'replenishment_need'] as $key) {
            $component->call('addWidget', $key);
        }

        $this->assertCount(4, $component->get('widgets'));
        $component->assertSee(__('At most :max expensive widgets are allowed (you have :count).', ['max' => 4, 'count' => 5]));
    }

    public function test_at_most_two_heatmaps(): void
    {
        $component = $this->builder();
        foreach (range(1, 3) as $i) {
            $component->call('addWidget', 'shop_leaderboard');
            $id = collect($component->get('widgets'))->last()['id'];
            $component->call('select', $id)->set('panelDiagram', 'heatmap')->call('applyPanel');
        }

        $heatmaps = collect($component->get('widgets'))->where('diagram', 'heatmap')->count();
        $this->assertSame(2, $heatmaps);
        $component->assertSee(__('At most :max heatmaps are allowed (you have :count).', ['max' => 2, 'count' => 3]));
    }

    // ---------------------------------------------------------------- save

    public function test_save_round_trips_the_layout_and_bumps_the_version(): void
    {
        $monitor = $this->customMonitor();
        $before = $monitor->layout_version;

        $component = $this->builder($monitor)
            ->call('addWidget', 'sales_revenue')->call('addWidget', 'top_items')->call('addWidget', 'hourly_sales');
        $top = collect($component->get('widgets'))->firstWhere('key', 'top_items');
        $component->call('select', $top['id'])->set('panelTopN', 7)->set('panelTitle', 'Best')->call('applyPanel')
            ->call('save')->assertSet('dirty', false);

        $monitor->refresh();
        $this->assertSame($before + 1, $monitor->layout_version);
        $this->assertSame($component->get('widgets'), $monitor->layout, 'what the editor shows is what is stored');
        $saved = collect($monitor->layout)->firstWhere('key', 'top_items');
        $this->assertSame(7, $saved['options']['top_n']);
        $this->assertSame('Best', $saved['options']['title']);

        // Opening the editor again gives the same layout back.
        $reopened = $this->builder($monitor);
        $this->assertSame($monitor->layout, $reopened->get('widgets'));
    }

    public function test_save_busts_the_cached_datasets_of_the_old_layout(): void
    {
        $this->sales(Shop::factory()->create(), '2026-10-07', '09:00:00', 2, 100);
        $monitor = $this->customMonitor(['sales_revenue']);
        \App\Monitor\MonitorData::build($this->scopeFor($monitor));
        $this->assertNotEmpty(\Illuminate\Support\Facades\Cache::get($this->scopeFor($monitor)->cacheKey('custom.receipts.l1.w0')));

        $this->builder($monitor)->call('addWidget', 'receipt_count')->call('save');

        $this->assertNull(\Illuminate\Support\Facades\Cache::get($this->scopeFor($monitor->fresh())->cacheKey('custom.receipts.l2.w0')), 'the new version starts with its own cache');
        $this->assertSame(2, $monitor->fresh()->layout_version);
    }

    public function test_the_preview_uses_the_same_renderer_with_real_data(): void
    {
        $this->sales(Shop::factory()->create(['name' => 'Alpha']), '2026-10-07', '09:00:00', 3, 100);
        $monitor = $this->customMonitor(['sales_revenue', 'receipt_ticker']);

        $component = $this->builder($monitor)->call('togglePreview')
            ->assertSee('data-widget="sales_revenue"', false)
            ->assertSee('300')
            ->assertSee('data-widget="receipt_ticker"', false);

        $component->set('previewAs', 'public')->assertDontSee('data-widget="receipt_ticker"', false);
    }

    public function test_the_preview_includes_unsaved_changes_and_the_canvas_has_the_drag_hooks(): void
    {
        $component = $this->builder()->call('addWidget', 'basket_size');

        $html = $component->html();
        $this->assertStringContainsString('monitorCanvas', $html);
        $this->assertStringContainsString("begin(\$event, 'move')", $html);
        foreach (['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as $edge) {
            $this->assertStringContainsString("begin(\$event, 'resize', '".$edge."')", $html, 'handle '.$edge);
        }
        $this->assertStringContainsString('beginPalette(', $html);
        $this->assertStringContainsString(__('Sample data'), $html);
        $this->assertStringContainsString('data-min-w="2"', $html);

        $component->call('togglePreview')->assertSee('data-widget="basket_size"', false);
    }

    // ---------------------------------------------------------------- constructor: edges, palette drop, redo, density

    public function test_resizing_from_any_edge_moves_position_and_size_together_within_the_catalog_limits(): void
    {
        $component = $this->builder()->call('addWidget', 'hourly_sales'); // 8 x 4 at 0,0 ; min 4 x 3
        $id = $component->get('widgets')[0]['id'];
        $component->call('move', $id, 2, 0);

        // West edge dragged one cell left: x-1, w+1.
        $component->call('place', $id, 1, 0, 9, 4);
        $w = $component->get('widgets')[0];
        $this->assertSame([1, 9, 4], [$w['x'], $w['w'], $w['h']]);

        // North-west corner shrinking beyond the minimum is clamped to the minimum size.
        $component->call('place', $id, 5, 0, 1, 1);
        $w = $component->get('widgets')[0];
        $this->assertSame([4, 3], [$w['w'], $w['h']]);

        // Too big is clamped to the maximum and kept inside the 12 columns.
        $component->call('place', $id, 8, 0, 40, 40);
        $w = $component->get('widgets')[0];
        $this->assertLessThanOrEqual(12, $w['w']);
        $this->assertLessThanOrEqual(8, $w['h']);
        $this->assertLessThanOrEqual(12, $w['x'] + $w['w']);

        $component->call('place', 'not-a-widget', 0, 0, 3, 3);
        $this->assertCount(1, $component->get('widgets'));
    }

    public function test_a_resize_pushes_the_widget_below_instead_of_overlapping_it(): void
    {
        $component = $this->builder()->call('addWidget', 'hourly_sales')->call('addWidget', 'trend_8d');
        [$a, $b] = $component->get('widgets');
        $component->call('place', $a['id'], 0, 0, 8, 7);

        $widgets = collect($component->get('widgets'));
        $first = $widgets->firstWhere('id', $a['id']);
        $second = $widgets->firstWhere('id', $b['id']);
        $this->assertFalse(Grid::overlaps($first, $second));
        $this->assertGreaterThanOrEqual($first['y'] + $first['h'], $second['y'], 'the neighbour is pushed below the grown widget');
    }

    public function test_dropping_from_the_palette_places_the_widget_at_the_cell(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue');

        $component->call('addWidgetAt', 'receipt_count', 6, 0);
        $dropped = collect($component->get('widgets'))->firstWhere('key', 'receipt_count');
        $this->assertSame([6, 0, 3, 2], [$dropped['x'], $dropped['y'], $dropped['w'], $dropped['h']]);
        $component->assertSet('selectedId', $dropped['id']);

        // Dropped onto an occupied cell: the new widget wins its spot, the old one is pushed down.
        $component->call('addWidgetAt', 'avg_check', 6, 0);
        $widgets = $component->get('widgets');
        foreach ($widgets as $i => $a) {
            foreach (array_slice($widgets, $i + 1) as $b) {
                $this->assertFalse(Grid::overlaps($a, $b));
            }
        }

        $count = count($widgets);
        $component->call('addWidgetAt', 'unknown_widget', 0, 0)->call('addWidgetAt', 'avg_check', 99, 99);
        $this->assertCount($count + 1, $component->get('widgets'), 'unknown key ignored, far drop clamped into the grid');
        $this->assertLessThanOrEqual(12, collect($component->get('widgets'))->max(fn ($w) => $w['x'] + $w['w']));
    }

    public function test_drop_respects_the_widget_limits(): void
    {
        $keys = array_slice(array_keys(WidgetCatalog::all()), 0, WidgetCatalog::MAX_WIDGETS);
        $component = $this->builder($this->customMonitor($keys));

        $component->call('addWidgetAt', 'sales_revenue', 0, 0);
        $this->assertCount(WidgetCatalog::MAX_WIDGETS, $component->get('widgets'));
        $this->assertNotEmpty($component->get('notices'));
    }

    public function test_undo_and_redo(): void
    {
        $component = $this->builder()->call('addWidget', 'sales_revenue')->call('addWidget', 'receipt_count');
        $this->assertCount(2, $component->get('widgets'));

        $component->call('undo');
        $this->assertCount(1, $component->get('widgets'));
        $component->call('redo');
        $this->assertCount(2, $component->get('widgets'));

        $component->call('undo')->call('addWidget', 'avg_check')->call('redo');
        $this->assertCount(2, $component->get('widgets'), 'a new edit clears the redo stack');
        $this->assertSame([], $component->get('future'));
    }

    public function test_row_density_is_stored_with_the_layout_and_defaults_to_the_original_behaviour(): void
    {
        $monitor = $this->customMonitor(['sales_revenue', 'hourly_sales']);
        $this->assertNull($monitor->layout_rows);
        $used = $monitor->gridRows();
        $this->assertSame(6, $used, 'null = exactly the rows the layout uses (before this feature)');

        $component = $this->builder($monitor)->assertSet('rowsSetting', 0);
        $component->set('rowsSetting', 12)->assertSet('dirty', true)->call('save');
        $monitor->refresh();
        $this->assertSame(12, $monitor->layout_rows);
        $this->assertSame(2, $monitor->layout_version, 'the layout version is bumped');
        $this->assertSame(12, $monitor->gridRows());

        // A layout taller than the density still gets the rows it needs.
        $tall = $this->customMonitor(['sales_revenue'], ['layout_rows' => 8]);
        $this->assertSame(8, $tall->gridRows());
        $this->assertSame(10, $tall->gridRows([['y' => 6, 'h' => 4]]));

        // Anything but the known choices means "auto"; the select cannot store arbitrary numbers.
        $this->builder($monitor)->set('rowsSetting', 7)->assertSet('rowsSetting', 0)->call('save');
        $this->assertNull($monitor->fresh()->layout_rows);
    }

    public function test_the_real_screen_uses_the_saved_row_density(): void
    {
        $user = User::factory()->create();
        $auto = $this->customMonitor(['sales_revenue', 'hourly_sales']);
        $fixed = $this->customMonitor(['sales_revenue', 'hourly_sales'], ['layout_rows' => 12]);

        $this->actingAs($user)->get(route('monitors.show', $auto))->assertOk()->assertSee('--rows: 6', false);
        $this->actingAs($user)->get(route('monitors.show', $fixed))->assertOk()->assertSee('--rows: 12', false);
    }

    public function test_the_canvas_draws_sample_data_and_preview_draws_real_data(): void
    {
        $shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->sales($shop, '2026-10-07', '09:00:00', 3, 100);
        $monitor = $this->customMonitor(['shop_leaderboard', 'sales_revenue'], [], [$shop->id]);

        $edit = $this->builder($monitor)
            ->assertSee(__('Sample data'))
            ->assertSee('data-widget="shop_leaderboard"', false)
            ->assertSee(__('Shop').' 1')
            ->assertDontSee('Alpha');

        $edit->call('togglePreview')->assertSee('Alpha')->assertDontSee(__('Shop').' 1');
    }

    public function test_the_edit_canvas_runs_no_queries_on_receipts_stock_or_items(): void
    {
        $monitor = $this->customMonitor(['sales_revenue', 'top_items', 'low_cover_items', 'gross_profit', 'hourly_sales']);
        $component = $this->builder($monitor);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $id = $component->get('widgets')[0]['id'];
        $component->call('move', $id, 3, 0)->call('place', $id, 3, 0, 4, 2)->call('addWidgetAt', 'avg_check', 0, 9);
        $log = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        foreach (['receipts', 'receipt_items', 'receipt_payments', 'stocks', 'items', 'item_prices', 'item_order_rules', 'order_items'] as $table) {
            $this->assertStringNotContainsString('"'.$table.'"', $log, 'no query on '.$table);
            $this->assertStringNotContainsString('`'.$table.'`', $log, 'no query on '.$table);
        }
    }

    public function test_new_constructor_actions_are_admin_only(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);
        $id = $monitor->layout[0]['id'];

        foreach ([['place', [$id, 1, 1, 4, 4]], ['addWidgetAt', ['avg_check', 0, 0]], ['redo', []]] as [$action, $args]) {
            $component = $this->builder($monitor);
            $this->actingAs(User::factory()->operator()->create());
            $component->call($action, ...$args)->assertForbidden();
            $this->actingAs($this->admin);
        }
    }

    public function test_palette_search_filters_the_catalog(): void
    {
        $this->builder()->set('search', 'refund')->assertSee(__('Refund rate'))->assertDontSee(__('Top items'));
    }

    // ---------------------------------------------------------------- catalog validation

    public function test_validation_rejects_an_invalid_metric_diagram_combination(): void
    {
        $widget = ['id' => (string) \Illuminate\Support\Str::uuid(), 'key' => 'sales_revenue', 'diagram' => 'heatmap', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2];

        $result = LayoutValidator::validate([$widget]);

        $this->assertCount(1, $result['errors']);
        $this->assertSame([], $result['layout']);
    }

    public function test_validation_rejects_unknown_keys_and_bad_numbers(): void
    {
        $ok = ['key' => 'sales_revenue', 'diagram' => 'kpi', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2];

        $this->assertNotEmpty(LayoutValidator::validate([['key' => 'nope'] + $ok])['errors']);
        $this->assertNotEmpty(LayoutValidator::validate([['x' => 'abc'] + $ok])['errors']);
        $this->assertNotEmpty(LayoutValidator::validate([['x' => 10, 'w' => 3] + $ok])['errors'], 'does not fit in 12 columns');
        $this->assertNotEmpty(LayoutValidator::validate([['w' => 99] + $ok])['errors'], 'over the maximum size');
        $this->assertNotEmpty(LayoutValidator::validate([['w' => 1] + $ok])['errors'], 'under the minimum size');
        $this->assertNotEmpty(LayoutValidator::validate([['x' => -1] + $ok])['errors']);
        $this->assertNotEmpty(LayoutValidator::validate('not an array')['errors']);
        $this->assertSame([], LayoutValidator::validate([$ok])['errors']);
    }

    public function test_validation_enforces_the_limits_and_normalises_ids_options_and_overlaps(): void
    {
        $base = ['key' => 'basket_size', 'diagram' => 'kpi', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 1];
        $this->assertNotEmpty(LayoutValidator::validate(array_fill(0, 17, $base))['errors']);
        $this->assertSame([], LayoutValidator::validate(array_fill(0, 16, $base))['errors']);

        $expensive = fn (string $k) => ['key' => $k, 'diagram' => WidgetCatalog::find($k)->default->value, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4];
        $five = array_map($expensive, ['top_items', 'gross_profit', 'profit_by_shop', 'wh_pick_list', 'replenishment_need']);
        $this->assertNotEmpty(LayoutValidator::validate($five)['errors']);
        $this->assertSame([], LayoutValidator::validate(array_slice($five, 0, 4))['errors']);

        $heat = ['key' => 'shop_leaderboard', 'diagram' => 'heatmap', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4];
        $this->assertNotEmpty(LayoutValidator::validate([$heat, $heat, $heat])['errors']);
        $this->assertSame([], LayoutValidator::validate([$heat, $heat])['errors']);

        $result = LayoutValidator::validate([
            $base + ['id' => 'not-a-uuid', 'options' => ['top_n' => 99, 'junk' => 'x', 'title' => '<i>Hi</i>']],
            $base,
        ]);
        $this->assertSame([], $result['errors']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result['layout'][0]['id']);
        $this->assertNotSame($result['layout'][0]['id'], $result['layout'][1]['id']);
        $this->assertSame(['title' => 'Hi'], $result['layout'][0]['options'], 'only supported options survive');
        $this->assertFalse(Grid::overlaps($result['layout'][0], $result['layout'][1]), 'overlaps are resolved');
    }

    public function test_the_catalog_is_validated_widget_by_widget_with_their_defaults(): void
    {
        foreach (WidgetCatalog::all() as $def) {
            foreach ($def->diagrams as $diagram) {
                $result = LayoutValidator::validate([['key' => $def->key, 'diagram' => $diagram->value, 'x' => 0, 'y' => 0, 'w' => $def->w, 'h' => $def->h]]);
                $this->assertSame([], $result['errors'], $def->key.' '.$diagram->value);
            }
        }
    }

    // ---------------------------------------------------------------- presets

    public function test_every_preset_is_a_valid_layout_with_the_requested_widgets(): void
    {
        $expected = [
            'executive' => ['sales_revenue', 'sales_net', 'receipt_count', 'avg_check', 'hourly_sales', 'payment_mix', 'shop_leaderboard', 'trend_8d', 'gross_profit', 'margin_pct'],
            'shop_manager' => ['sales_revenue', 'receipt_count', 'avg_check', 'refund_rate', 'hourly_sales', 'receipt_ticker', 'low_cover_items', 'top_items'],
            'warehouse' => ['stockout_count', 'wh_shortage', 'wh_cover', 'replenishment_need', 'wh_pick_list', 'low_cover_items'],
            'cashier_operations' => ['refund_rate', 'cancelled_rate', 'discount_rate', 'tills_active', 'receipt_ticker', 'shop_leaderboard'],
            'fmcg_buyer' => ['top_items', 'low_cover_items', 'margin_pct', 'profit_by_shop', 'sync_freshness'],
            'receipts' => ['receipt_count', 'sales_revenue', 'avg_check', 'basket_size', 'hourly_sales', 'payment_mix', 'receipt_ticker', 'refund_rate', 'cancelled_rate', 'tills_active', 'discount_rate', 'shop_leaderboard'],
            'assortment' => ['a_items_out', 'unsold_items', 'wh_out_of_stock', 'customer_share', 'abc_mix', 'abc_xyz_matrix', 'unsold_by_group'],
            'blank' => [],
        ];

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys(Presets::all()));
        foreach ($expected as $key => $widgets) {
            $layout = Presets::layout($key);
            $this->assertSame([], LayoutValidator::validate($layout)['errors'], $key);
            $this->assertEqualsCanonicalizing($widgets, array_column($layout, 'key'), $key);
        }

        $executive = collect(Presets::layout('executive'))->keyBy('key');
        $this->assertSame([6, 4], [$executive['hourly_sales']['w'], $executive['hourly_sales']['h']]);
        $this->assertSame([3, 4], [$executive['payment_mix']['w'], $executive['payment_mix']['h']]);
        $this->assertSame('heatmap', collect(Presets::layout('cashier_operations'))->firstWhere('key', 'shop_leaderboard')['diagram']);
    }

    public function test_a_monitor_type_registry_includes_custom(): void
    {
        $this->assertTrue(view()->exists(MonitorType::Custom->view()));
        $this->assertTrue(method_exists(\App\Livewire\MonitorScreen::class, MonitorType::Custom->dataMethod()));
    }
}
