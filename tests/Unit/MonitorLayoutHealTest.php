<?php

namespace Tests\Unit;

use App\Monitor\Grid;
use App\Monitor\Presets;
use App\Monitor\Render;
use Tests\TestCase;

class MonitorLayoutHealTest extends TestCase
{
    private function w(string $id, string $key, int $x, int $y, int $w, int $h): array
    {
        return ['id' => $id, 'key' => $key, 'diagram' => 'kpi', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'options' => []];
    }

    public function test_a_hidden_widget_beside_a_chart_gives_its_columns_to_the_chart(): void
    {
        $chart = $this->w('a', 'hourly_sales', 0, 0, 8, 4);
        $ticker = $this->w('b', 'receipt_ticker', 8, 0, 4, 4);
        $below = $this->w('c', 'top_items', 0, 4, 6, 4);

        $healed = collect(Grid::heal([$chart, $below], [$ticker]))->keyBy('id');

        $this->assertSame([0, 0, 12, 4], [$healed['a']['x'], $healed['a']['y'], $healed['a']['w'], $healed['a']['h']]);
        $this->assertSame(4, $healed['c']['y']);
    }

    public function test_a_hole_in_the_middle_is_shared_by_both_neighbours_and_rows_close_up(): void
    {
        $left = $this->w('l', 'stockout_count', 0, 0, 3, 2);
        $right = $this->w('r', 'refund_rate', 9, 0, 3, 2);
        $gone = [$this->w('g1', 'gross_profit', 3, 0, 3, 2), $this->w('g2', 'margin_pct', 6, 0, 3, 2)];
        $next = $this->w('n', 'trend_8d', 0, 2, 6, 3);

        $healed = collect(Grid::heal([$left, $right, $next], $gone))->keyBy('id');

        $this->assertSame(12, $healed['l']['w'] + $healed['r']['w']);
        $this->assertSame($healed['l']['x'] + $healed['l']['w'], $healed['r']['x']);
        $this->assertSame(2, $healed['n']['y']);
    }

    public function test_presets_fit_a_tv_screen_and_have_no_overlap(): void
    {
        foreach (array_keys(Presets::all()) as $key) {
            $layout = Presets::layout($key);
            if ($layout === []) {
                continue; // the blank template
            }
            $rows = max(array_map(fn ($w) => $w['y'] + $w['h'], $layout));
            $this->assertLessThanOrEqual(10, $rows, $key.' needs scrolling on a 16:9 screen');
            foreach ($layout as $i => $a) {
                $this->assertArrayNotHasKey('fixed', $a);
                foreach (array_slice($layout, $i + 1) as $b) {
                    $this->assertFalse(Grid::overlaps($a, $b), $key);
                }
            }
        }
    }

    public function test_axis_helpers(): void
    {
        $this->assertSame(1.2, Render::niceMax(1.05));
        $this->assertSame(12_000_000.0, Render::niceMax(10_400_000));
        $this->assertSame(1.0, Render::niceMax(0));
        $this->assertSame('1.2M', Render::compact(1_200_000));
        $this->assertSame('750k', Render::compact(750_000));
        $this->assertSame('80', Render::compact(80));
    }
}
