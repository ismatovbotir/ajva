<?php

namespace App\Monitor;

use App\Services\SalesMetrics;
use App\Support\MonitorScope;

/**
 * Generated, deterministic SAMPLE datasets for the builder canvas: the same shapes Datasets builds from the
 * database (receipts board, hourly, trend, top_items, profit, inventory, sync), so the real WidgetPayloads class turns
 * them into exactly the payloads the real monitor draws. No database access, no ForecastService, no cost query:
 * the numbers are invented, so nothing real (and no profit) can leak, and the canvas works for a monitor with no data.
 *
 * Deterministic on purpose (fixed tables, no random numbers, a frozen "14:30" clock): the canvas must not flicker
 * on a redraw. Shops are "Shop 1..N", items are plain FMCG names. The deltas are chosen to show every state
 * (ok, warn, bad) so the colours of the real widgets can be judged while arranging them.
 */
class SampleDatasets extends Datasets
{
    public const SHOPS = 7;

    public const CLOCK = '14:30';

    public const HOUR = 14;

    /** @var array<string, array<string, mixed>> */
    private array $sample = [];

    private MonitorScope $sampleScope;

    public function __construct(MonitorScope $scope)
    {
        parent::__construct($scope, []);
        $this->sampleScope = $scope;
    }

    public function built(): array
    {
        return array_keys($this->sample);
    }

    /** @return array<string, mixed> */
    public function get(string $name): array
    {
        return $this->sample[$name] ??= ['generated_at' => $this->sampleScope->now->copy()->setTimeFromTimeString(self::CLOCK.':00')->timestamp] + $this->make($name);
    }

    public function salesShopIds(): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    private function make(string $name): array
    {
        return match ($name) {
            'receipts' => $this->receipts(),
            'hourly' => $this->hourly(),
            'trend' => $this->trend(),
            'top_items' => $this->topItems(),
            'profit' => $this->profit(),
            'inventory' => $this->inventory(),
            'sync' => $this->sync(),
            'assortment' => $this->assortment(),
            'unsold' => $this->unsold(),
            'wh_oos' => $this->warehouseOutOfStock(),
            'customers' => $this->customers(),
            'security_week' => $this->securityWeek(),
            'security_today' => $this->securityToday(),
            'data_health' => $this->dataHealth(),
            'sales_gaps' => $this->salesGaps(),
            'markdown' => $this->markdown(),
            'markdown_discounts' => $this->markdownDiscounts(),
            default => [],
        };
    }

    /** Markdown candidates: invented slow stock at the sell price. */
    private function markdown(): array
    {
        $s = $this->shopNames();
        $item = fn (string $name, string $shop, ?float $value, float $qty, ?int $depth, bool $fresh = false) => ['item' => $name, 'shop' => $shop, 'value' => $value, 'on_hand' => $qty, 'depth' => $depth, 'fresh' => $fresh];

        return [
            'as_of' => self::CLOCK,
            'days' => 60,
            'stock_as_of' => $this->sampleScope->now->copy()->subDay()->toDateString(),
            'candidates' => 42, 'units' => 610.0, 'value' => 18_400_000.0, 'no_price' => 2, 'not_reported' => 5, 'truncated' => false,
            'shops' => [
                ['name' => $s[3], 'pairs' => 14, 'units' => 240.0, 'value' => 7_200_000.0, 'no_price' => 1],
                ['name' => $s[1], 'pairs' => 11, 'units' => 150.0, 'value' => 5_100_000.0, 'no_price' => 0],
                ['name' => $s[5], 'pairs' => 9, 'units' => 120.0, 'value' => 3_900_000.0, 'no_price' => 1],
                ['name' => $s[2], 'pairs' => 8, 'units' => 100.0, 'value' => 2_200_000.0, 'no_price' => 0],
            ],
            'items' => [
                $item('Green tea 100 bags', $s[3], 1_450_000.0, 48.0, 30),
                $item('Laundry powder 3 kg', $s[1], 1_120_000.0, 20.0, 20),
                $item('Yogurt 1 kg', $s[5], 640_000.0, 32.0, null, true),
                $item('Instant coffee 200 g', $s[3], 590_000.0, 9.0, 10),
                $item('Notebook A5', $s[2], null, 40.0, 20),
            ],
        ];
    }

    /** Discount give-away: one pair judged, one cannibalised, one not enough data. */
    private function markdownDiscounts(): array
    {
        $s = $this->shopNames();

        return [
            'as_of' => self::CLOCK,
            'days' => 60,
            'pairs' => 3, 'judged' => 2, 'give_away' => 1_480_000.0,
            'items' => [
                ['item' => 'Sunflower oil 1 l', 'shop' => $s[3], 'give_away' => 820_000.0, 'lift' => 1.6, 'cannibalised' => false],
                ['item' => 'Sugar 1 kg', 'shop' => $s[1], 'give_away' => 460_000.0, 'lift' => 1.05, 'cannibalised' => true],
                ['item' => 'Pasta 500 g', 'shop' => $s[5], 'give_away' => 200_000.0, 'lift' => null, 'cannibalised' => false],
            ],
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<int, string> */
    private function shopNames(): array
    {
        $names = [];
        for ($i = 1; $i <= self::SHOPS; $i++) {
            $names[$i] = __('Shop').' '.$i;
        }

        return $names;
    }

    /** @return array<int, string> */
    public static function itemNames(): array
    {
        return [
            'Milk 1 l', 'Bread white', 'Sunflower oil 1 l', 'Sugar 1 kg', 'Rice 1 kg', 'Tea black 100 g', 'Eggs 10 pcs', 'Water 1.5 l',
            'Pasta 400 g', 'Flour 2 kg', 'Cola 1.5 l', 'Butter 180 g', 'Yogurt 500 g', 'Salt 1 kg', 'Coffee 3 in 1',
        ];
    }

    /**
     * One window of a receipts figure set (the shape of ReceiptsMetrics::derive).
     *
     * @return array<string, int|float>
     */
    private function window(int $count, float $sum, float $basket, float $discountPct, int $refunds, float $refundSum, int $cancelled): array
    {
        $all = $count + $refunds + $cancelled;
        $discount = $sum * $discountPct / max(0.0001, 100 - $discountPct);

        return [
            'count' => $count,
            'sum' => $sum,
            'avg' => $count > 0 ? $sum / $count : 0.0,
            'basket' => $basket,
            'discount' => $discount,
            'discount_percent' => ($sum + $discount) > 0 ? $discount / ($sum + $discount) * 100 : 0.0,
            'refund_count' => $refunds,
            'refund_sum' => $refundSum,
            'refund_percent' => $all > 0 ? $refunds / $all * 100 : 0.0,
            'refund_amount_percent' => $sum > 0 ? $refundSum / $sum * 100 : 0.0,
            'cancelled_count' => $cancelled,
            'cancelled_sum' => $cancelled * 41000.0,
            'cancelled_percent' => $all > 0 ? $cancelled / $all * 100 : 0.0,
            'all' => $all,
        ];
    }

    // ------------------------------------------------------------------ datasets

    private function receipts(): array
    {
        // Per shop: [today sum, yesterday/today, last week/today]; the ratios give every colour state.
        $table = [
            1 => [4_650_000, 0.94, 1.00], 2 => [3_980_000, 1.03, 1.08], 3 => [3_340_000, 1.10, 0.95], 4 => [2_790_000, 0.88, 1.22],
            5 => [2_310_000, 0.97, 1.05], 6 => [1_760_000, 1.20, 0.90], 7 => [1_390_000, 1.00, 1.40],
        ];
        $names = $this->shopNames();
        $rows = [];
        $tot = ['today' => [0, 0.0, 0, 0.0, 0], 'yesterday' => [0, 0.0, 0, 0.0, 0], 'week' => [0, 0.0, 0, 0.0, 0]];
        foreach ($table as $id => [$sum, $y, $w]) {
            $row = ['id' => $id, 'name' => $names[$id]];
            foreach (['today' => 1.0, 'yesterday' => $y, 'week' => $w] as $key => $k) {
                $s = round($sum * $k, -3);
                $count = (int) round($s / (52_000 + $id * 1_800));
                $refunds = (int) round($count * 0.012);
                $cancelled = (int) round($count * 0.026);
                $row[$key] = $this->window($count, $s, 3.1 + $id * 0.15, 2.8 + $id * 0.2, $refunds, round($s * 0.014), $cancelled);
                $tot[$key][0] += $count;
                $tot[$key][1] += $s;
                $tot[$key][2] += $refunds;
                $tot[$key][3] += round($s * 0.014);
                $tot[$key][4] += $cancelled;
            }
            $rows[] = $row;
        }

        $win = fn (string $key) => $this->window($tot[$key][0], $tot[$key][1], 3.6, 3.1, $tot[$key][2], $tot[$key][3], $tot[$key][4]);

        $payments = [['Cash', 41.0], ['Uzcard', 28.5], ['Humo', 17.0], ['Payme', 8.5], ['Click', 5.0]];
        $total = $win('today')['sum'];
        $payments = array_map(fn (array $p, int $i) => [
            'name' => $p[0],
            'sum' => number_format($total * $p[1] / 100, 0, '.', ' '),
            'percent' => $p[1],
            'color' => SalesMetrics::COLORS[$i] ?? SalesMetrics::COLOR_OTHER,
        ], $payments, array_keys($payments));

        $latest = [];
        $minute = 30;
        for ($i = 0; $i < 20; $i++) {
            $shop = ($i * 3) % self::SHOPS + 1;
            $minute -= 1 + ($i % 3);
            $tag = $i === 4 ? 'refund' : ($i === 11 ? 'cancelled' : null);
            $latest[] = [
                'id' => 1000 - $i,
                'time' => sprintf('14:%02d:%02d', max(0, $minute), (17 * $i) % 60),
                'shop' => $names[$shop],
                'pos' => __('Till').' '.(($i % 2) + 1),
                'total' => (float) (28_000 + (($i * 37_000) % 211_000)),
                'cancelled' => $tag === 'cancelled',
                'refund' => $tag === 'refund',
                'payment' => $payments[$i % 3]['name'],
            ];
        }

        $today = $win('today');
        $yesterday = $win('yesterday');
        $week = $win('week');

        return [
            'board' => [
                'asOf' => self::CLOCK,
                'today' => $today,
                'yesterday' => $yesterday,
                'week' => $week,
                'shops' => $rows,
                'payments' => $payments,
                'latest' => $latest,
                'tills' => ['active' => 11, 'total' => 14],
            ],
            'week_day_has_data' => true,
        ];
    }

    private function hourly(): array
    {
        $shape = [0, 0, 0, 0, 0, 0, 0.03, 0.10, 0.28, 0.52, 0.74, 0.88, 0.96, 1.0, 0.92, 0.80, 0.84, 0.95, 1.0, 0.82, 0.55, 0.30, 0.10, 0.02];
        $series = [];
        foreach (['sum' => 2_450_000.0, 'count' => 46.0] as $metric => $peak) {
            $hours = [];
            for ($h = 0; $h < 24; $h++) {
                $today = $h <= self::HOUR ? round($shape[$h] * $peak * (1 + (($h * 7) % 5 - 2) * 0.03), $metric === 'count' ? 0 : -3) : 0.0;
                $yesterday = round($shape[$h] * $peak * (0.92 + (($h * 3) % 4) * 0.03), $metric === 'count' ? 0 : -3);
                $week = round($shape[$h] * $peak * (1.04 + (($h * 5) % 3) * 0.04), $metric === 'count' ? 0 : -3);
                $hours[] = [
                    'hour' => sprintf('%02d', $h), 'total' => $today, 'y_total' => $yesterday, 'w_total' => $week,
                    'height' => 0, 'marker' => 0, 'w_marker' => 0, 'today' => '', 'yesterday' => '', 'last_week' => '', 'segments' => [],
                ];
            }
            $series[$metric] = $hours;
        }

        return ['series' => $series, 'current_hour' => self::HOUR];
    }

    private function trend(): array
    {
        $revenue = [17_200_000, 15_100_000, 14_000_000, 19_300_000, 21_000_000, 16_800_000, 17_900_000, 18_420_000];
        $count = [331, 290, 268, 372, 404, 323, 344, 355];
        $from = $this->sampleScope->now->copy()->startOfDay()->subDays(7);
        $days = [];
        foreach ($revenue as $i => $r) {
            $days[] = ['label' => $from->copy()->addDays($i)->format('d.m'), 'revenue' => (float) $r, 'count' => $count[$i]];
        }

        return ['days' => $days];
    }

    private function topItems(): array
    {
        $items = [];
        foreach (self::itemNames() as $i => $name) {
            $qty = (float) (118 - $i * 7 - ($i % 3) * 2);
            $items[] = [
                'name' => $name,
                'qty' => $qty,
                'sum' => round($qty * (9_000 + (($i * 13) % 11) * 2_300), -2),
                'prev_qty' => round($qty * [0.95, 1.08, 1.30, 0.80, 1.0][$i % 5]),
                'week_qty' => round($qty * [1.02, 0.97, 1.15, 1.45, 0.90][$i % 5]),
            ];
        }

        return ['items' => $items, 'prev_base' => 140, 'week_base' => 150, 'as_of' => self::CLOCK];
    }

    private function profit(): array
    {
        $names = $this->shopNames();
        $rev = [4_650_000, 3_980_000, 3_340_000, 2_790_000, 2_310_000, 1_760_000, 1_390_000];
        $margin = [0.215, 0.188, 0.172, 0.205, 0.140, 0.231, 0.119];
        $out = ['today' => [], 'prev' => [], 'week' => [], 'shops' => $names, 'as_of' => self::CLOCK];
        foreach ($rev as $i => $r) {
            $id = $i + 1;
            foreach (['today' => [1.0, 0.0], 'prev' => [0.97, 0.004], 'week' => [1.04, 0.012]] as $key => [$k, $shift]) {
                $covered = $r * $k * 0.92;
                $out[$key][$id] = ['profit' => round($covered * ($margin[$i] + $shift)), 'covered' => round($covered), 'revenue' => round($r * $k)];
            }
        }

        return $out;
    }

    private function inventory(): array
    {
        $names = $this->shopNames();
        $items = self::itemNames();
        $shops = [];
        $pick = [];
        foreach ([1, 2, 3, 4, 5] as $s) {
            $rows = [];
            foreach (array_slice($items, $s % 4, 8) as $j => $item) {
                $level = $j === 0 && $s <= 2 ? 'out' : ($j === 1 ? 'critical' : ($j < 4 ? 'soon' : 'low'));
                $cover = $level === 'out' ? 0.0 : [0.0, 0.6, 1.8, 2.4, 3.6, 4.2, 5.0, 6.1][$j];
                $rows[] = [
                    'item' => $item, 'level' => $level, 'urgency' => $level, 'cover' => $level === 'out' ? 0.0 : $cover,
                    'estimated' => $level === 'out' ? 0.0 : (float) (4 + $j * 3), 'need' => (float) (24 - $j * 2),
                    'send' => (float) (24 - $j * 2), 'short' => 0.0, 'starved' => false,
                ];
            }
            $counts = ['out' => 0, 'critical' => 0, 'soon' => 0, 'low' => 0];
            foreach ($rows as $r) {
                $counts[$r['urgency']]++;
            }
            $shops[] = ['id' => $s, 'name' => $names[$s], 'count' => count($rows), 'counts' => $counts, 'short' => 0, 'rows' => $rows];
        }
        foreach (array_slice($items, 0, 15) as $j => $item) {
            $need = (float) (140 - $j * 8);
            $short = $j % 4 === 1 ? (float) round($need * 0.35) : 0.0;
            $pick[] = ['item' => $item, 'need' => $need, 'send' => $need - $short, 'short' => $short, 'starved' => $j === 1];
        }

        return [
            'plan' => [
                'warehouse_configured' => true,
                'kpi' => ['shops' => 5, 'lines' => 40, 'items' => 15, 'out' => 2, 'critical' => 5, 'soon' => 9, 'low' => 24, 'short_items' => 4, 'short_lines' => 7, 'starved' => 1],
                'shops' => $shops,
                'pick' => $pick,
            ],
            'ruled' => 240,
            'warehouse_name' => __('Warehouse'),
            'stock_as_of' => $this->sampleScope->now->copy()->subDay()->toDateString(),
        ];
    }

    private function assortment(): array
    {
        // Items and revenue share per ABC x XYZ cell (sums: 80 / 15 / 5 % of sales).
        $cells = [
            'A' => ['X' => [38, 52.0], 'Y' => [41, 21.0], 'Z' => [14, 7.0]],
            'B' => ['X' => [62, 6.0], 'Y' => [118, 5.5], 'Z' => [64, 3.5]],
            'C' => ['X' => [95, 1.6], 'Y' => [240, 1.9], 'Z' => [388, 1.5]],
        ];
        $matrix = [];
        $abc = [];
        foreach ($cells as $class => $cols) {
            $abc[$class] = ['items' => 0, 'share' => 0.0];
            foreach ($cols as $x => [$items, $share]) {
                $matrix[$class][$x] = ['items' => $items, 'rev' => $share * 100_000, 'share' => $share];
                $abc[$class]['items'] += $items;
                $abc[$class]['share'] += $share;
            }
        }

        return [
            'items' => array_sum(array_column($abc, 'items')),
            'weeks' => 12,
            'xyz' => true,
            'abc' => $abc,
            'matrix' => $matrix,
            'a_at_risk' => 6,
            'stock_as_of' => $this->sampleScope->now->copy()->subDay()->toDateString(),
        ];
    }

    private function unsold(): array
    {
        $groups = [['Snacks', 46, 120], ['Drinks', 31, 140], ['Household', 28, 60], ['Sweets', 19, 95], ['Dairy', 9, 110], ['Bakery', 4, 40]];

        return [
            'days' => 60,
            'kpi' => [
                'stocked_items' => 565, 'unsold_items' => 137, 'unsold_share' => 137 / 565 * 100, 'unsold_units' => 4210.0,
                'stock_as_of' => $this->sampleScope->now->copy()->subDay()->toDateString(),
            ],
            'groups' => array_map(fn (array $g) => ['group' => $g[0], 'unsold' => $g[1], 'stocked' => $g[2], 'share' => $g[1] / $g[2] * 100], $groups),
        ];
    }

    private function warehouseOutOfStock(): array
    {
        return [
            'state' => 'ok',
            'ref' => $this->sampleScope->now->copy()->subDay()->toDateString(),
            'chain' => $this->sampleScope->now->copy()->subDay()->toDateString(),
            'partial' => false,
            'warehouse' => __('Warehouse'),
            'kpi' => ['out' => 14, 'a' => 3, 'share' => 4.2, 'need' => 960.0, 'need_items' => 9, 'negative' => 0, 'not_reported' => 0],
        ];
    }

    private function customers(): array
    {
        return ['all' => 355, 'with' => 118];
    }

    /** Invented cashiers ("Cashier A"...) and shops: the canvas never shows a real name. */
    private function securityWeek(): array
    {
        $shops = $this->shopNames();
        $cashier = fn (string $letter) => __('Cashier').' '.$letter;

        return [
            'as_of' => self::CLOCK,
            'receipts' => 2480,
            'kpi' => ['total' => 9, 'high' => 2, 'medium' => 3, 'low' => 4, 'shops' => 5, 'cashiers' => 4, 'tills' => 2],
            'chain' => ['all' => 2480, 'cancelled' => 82, 'empty' => 24],
            'prev_chain' => ['all' => 2390, 'cancelled' => 52, 'empty' => 11],
            'judgeable' => 12,
            'refund' => [
                ['shop' => $shops[2], 'cashier' => $cashier('A'), 'severity' => 'high', 'value' => 9.4, 'baseline' => 1.8, 'sample' => 212],
                ['shop' => $shops[5], 'cashier' => $cashier('B'), 'severity' => 'medium', 'value' => 5.1, 'baseline' => 2.0, 'sample' => 148],
                ['shop' => $shops[1], 'cashier' => $cashier('C'), 'severity' => 'medium', 'value' => 4.2, 'baseline' => 1.9, 'sample' => 96],
                ['shop' => $shops[4], 'cashier' => $cashier('D'), 'severity' => 'low', 'value' => 6.0, 'baseline' => 2.1, 'sample' => 24],
            ],
            'discount' => [
                ['shop' => $shops[3], 'cashier' => $cashier('E'), 'severity' => 'high', 'value' => 18.5, 'baseline' => 3.1, 'sample' => 187],
                ['shop' => $shops[6], 'cashier' => $cashier('F'), 'severity' => 'medium', 'value' => 9.8, 'baseline' => 3.4, 'sample' => 133],
                ['shop' => $shops[1], 'cashier' => $cashier('C'), 'severity' => 'low', 'value' => 8.0, 'baseline' => 3.3, 'sample' => 25],
            ],
        ];
    }

    private function securityToday(): array
    {
        $shops = $this->shopNames();

        return [
            'as_of' => self::CLOCK,
            'sale_receipts' => 355,
            'basket_shops' => 7,
            'normal_shops' => 7,
            'off' => ['count' => 4, 'severity' => 'medium'],
            'silent' => [
                ['shop' => $shops[6], 'hours' => 4, 'expected' => 31, 'severity' => 'medium'],
                ['shop' => $shops[7], 'hours' => 6, 'expected' => 22, 'severity' => 'high'],
            ],
            'gaps' => ['missing' => 5, 'tills' => 2, 'severity' => 'medium'],
            'dups' => ['count' => 1, 'severity' => 'medium'],
            'integrity' => ['count' => 3, 'shops' => 2, 'severity' => 'high'],
            'baskets' => [
                ['shop' => $shops[2], 'total' => 4_850_000.0, 'cut' => 610_000.0, 'severity' => 'high'],
                ['shop' => $shops[4], 'total' => 1_720_000.0, 'cut' => 540_000.0, 'severity' => 'medium'],
                ['shop' => $shops[1], 'total' => 1_380_000.0, 'cut' => 590_000.0, 'severity' => 'medium'],
            ],
        ];
    }

    private function dataHealth(): array
    {
        $shops = $this->shopNames();

        return [
            'as_of' => self::CLOCK,
            'shops' => 7,
            'judged' => 7,
            'problems' => [
                ['shop' => $shops[7], 'last24' => 3, 'usual' => 140.0, 'status' => 'bad'],
                ['shop' => $shops[4], 'last24' => 48, 'usual' => 120.0, 'status' => 'warn'],
            ],
            'rejected' => ['total' => 14, 'spike' => false, 'by_reason' => ['unknown_item' => 11, 'qty_zero' => 3]],
        ];
    }

    /** Sales gaps: invented shops with every state (flagged, fine, not judged), card lever on, a few class A items out. */
    private function salesGaps(): array
    {
        $s = $this->shopNames();
        $na = ['status' => 'na', 'flag' => false, 'gap_pct' => null, 'trim_check' => null, 'recoverable' => null];
        $noCard = ['status' => 'na', 'flag' => false, 'share' => null, 'target' => null, 'uplift' => null];
        $none = ['out_a' => 0, 'valued' => 0, 'unknown' => 0, 'no_price' => 0, 'not_reported' => 0, 'lost_per_day' => 0.0];
        $row = fn (string $name, int $receipts, array $check, array $card, array $avail) => ['name' => $name, 'receipts' => $receipts, 'silent_days' => 0,
            'check' => $check, 'card' => $card, 'avail' => $avail];
        $chk = fn (float $gap, float $trim, float $money) => ['status' => 'ok', 'flag' => $gap > 8, 'gap_pct' => $gap, 'trim_check' => $trim, 'recoverable' => $money];
        $crd = fn (float $share, float $uplift) => ['status' => 'ok', 'flag' => 28.0 - $share >= 5, 'share' => $share, 'target' => 28.0, 'uplift' => $uplift];

        return [
            'as_of' => self::CLOCK,
            'days' => 28,
            'stock_as_of' => $this->sampleScope->now->copy()->subDay()->toDateString(),
            'min_receipts' => 200,
            'check' => ['value' => 52000.0, 'basis' => 'p75', 'shops' => 6],
            'card' => ['value' => 0.28, 'basis' => 'p75', 'shops' => 6, 'reason' => null, 'card_receipts' => 1450],
            'shops' => [
                $row($s[3], 1900, $chk(21.5, 40800.0, 4_200_000.0), $crd(12.0, 1_900_000.0), ['out_a' => 2, 'valued' => 2, 'unknown' => 0, 'no_price' => 0, 'not_reported' => 3, 'lost_per_day' => 310_000.0]),
                $row($s[5], 1500, $chk(12.3, 45600.0, 2_100_000.0), $crd(21.0, 700_000.0), ['out_a' => 1, 'valued' => 1, 'unknown' => 0, 'no_price' => 0, 'not_reported' => 0, 'lost_per_day' => 95_000.0]),
                $row($s[1], 2300, $chk(9.1, 47300.0, 1_300_000.0), $crd(29.0, 0.0), $none),
                $row($s[2], 2100, $chk(2.0, 51000.0, 300_000.0), $crd(33.0, 0.0), ['out_a' => 1, 'valued' => 0, 'unknown' => 1, 'no_price' => 0, 'not_reported' => 0, 'lost_per_day' => 0.0]),
                $row($s[4], 1800, $chk(-3.0, 53500.0, 0.0), $crd(26.0, 0.0), $none),
                $row($s[6], 2600, $chk(-6.0, 55100.0, 0.0), $crd(35.0, 0.0), $none),
                $row($s[7], 120, $na, $noCard, $none),
            ],
            'items' => [
                ['shop' => $s[3], 'item' => 'Milk 1 l', 'state' => 'valued', 'per_day' => 190_000.0, 'days_out' => 4],
                ['shop' => $s[3], 'item' => 'Bread white', 'state' => 'valued', 'per_day' => 120_000.0, 'days_out' => 2],
                ['shop' => $s[5], 'item' => 'Sunflower oil 1 l', 'state' => 'valued', 'per_day' => 95_000.0, 'days_out' => 6],
                ['shop' => $s[2], 'item' => 'Eggs 10 pcs', 'state' => 'unknown', 'per_day' => 0.0, 'days_out' => 1],
            ],
        ];
    }

    private function sync(): array
    {
        return ['stock_date' => $this->sampleScope->now->copy()->subDay()->toDateString(), 'hours' => 18.5];
    }
}
