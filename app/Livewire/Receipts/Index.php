<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Receipts'])]
class Index extends Component
{
    private const COLORS = [
        '#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#c23a3a', '#a13a7a',
    ];

    private const COLOR_OTHER = '#898781';

    /** Seconds the day's aggregates are reused, so paging the list doesn't recompute them. */
    private const ANALYTICS_TTL = 30;

    public string $date = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function updatedDate(): void
    {
        if (! $this->parsedDate()) {
            $this->date = now()->toDateString();
        }
    }

    public function render()
    {
        $day = $this->parsedDate() ?? now()->startOfDay();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $ids = ShopAccess::ids();

        $analytics = Cache::remember(
            ShopAccess::salesKey('receipts.analytics', $from->toDateString(), $ids),
            self::ANALYTICS_TTL,
            fn () => $this->buildAnalytics($from, $to, $ids),
        );

        return view('livewire.receipts.index', [
            'noShops' => $ids === [],
            'receipts' => ShopAccess::restrictTo(Receipt::query(), 'receipts.shop_id', $ids)
                ->with(['pos', 'shop'])
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
        ] + $analytics);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAnalytics(Carbon $from, Carbon $to, ?array $ids): array
    {
        $shops = ShopAccess::restrictTo(DB::table('shops'), 'id', $ids)->pluck('name', 'id')->all();

        // Refunds count negatively so the totals reflect net sales.
        $sign = fn ($sell) => $sell ? 1 : -1;

        // Shop totals by payment type.
        $paymentRows = ShopAccess::restrictTo(DB::table('receipt_payments')
            ->join('receipts', 'receipts.id', '=', 'receipt_payments.receipt_id'), 'receipts.shop_id', $ids)
            ->where('receipts.active', true)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->get(['receipts.shop_id', 'receipts.sell', 'receipt_payments.payment', 'receipt_payments.value']);

        $paymentTypes = $paymentRows->pluck('payment')->unique()->sort()->values()->all();
        $matrix = [];
        foreach ($paymentRows as $row) {
            $matrix[$row->shop_id][$row->payment] = ($matrix[$row->shop_id][$row->payment] ?? 0)
                + $sign($row->sell) * (float) $row->value;
        }

        $paymentTable = [];
        $columnTotals = array_fill_keys($paymentTypes, 0.0);
        foreach ($matrix as $shopId => $cells) {
            $rowCells = [];
            foreach ($paymentTypes as $type) {
                $rowCells[$type] = $cells[$type] ?? 0.0;
                $columnTotals[$type] += $rowCells[$type];
            }
            $paymentTable[] = [
                'name' => $shops[$shopId] ?? '#'.$shopId,
                'cells' => $rowCells,
                'total' => array_sum($rowCells),
            ];
        }
        usort($paymentTable, fn ($a, $b) => $b['total'] <=> $a['total']);

        // Shop totals by hour — one pass over the day's receipts, no model hydration.
        $hourly = [];
        $shopTotals = [];
        $count = 0;
        $receiptRows = ShopAccess::restrictTo(DB::table('receipts'), 'shop_id', $ids)
            ->where('active', true)
            ->whereBetween('created_at', [$from, $to])
            ->get(['shop_id', 'sell', 'total', 'created_at']);

        foreach ($receiptRows as $r) {
            $amount = $sign($r->sell) * (float) $r->total;
            $hour = (int) substr((string) $r->created_at, 11, 2);
            $hourly[$hour][$r->shop_id] = ($hourly[$hour][$r->shop_id] ?? 0) + $amount;
            $shopTotals[$r->shop_id] = ($shopTotals[$r->shop_id] ?? 0) + $amount;
            $count++;
        }

        arsort($shopTotals);
        $colors = [];
        $i = 0;
        foreach (array_keys($shopTotals) as $shopId) {
            $colors[$shopId] = self::COLORS[$i] ?? self::COLOR_OTHER;
            $i++;
        }

        $legend = collect($colors)->map(fn ($color, $shopId) => [
            'name' => $shops[$shopId] ?? '#'.$shopId,
            'color' => $color,
        ])->values()->all();

        return [
            'paymentTypes' => $paymentTypes,
            'paymentTable' => $paymentTable,
            'columnTotals' => $columnTotals,
            'grandTotal' => array_sum($columnTotals),
            'chart' => $this->lineChart($hourly, $colors, $shops),
            'legend' => $legend,
            'summary' => [
                'total' => array_sum($shopTotals),
                'count' => $count,
            ],
        ];
    }

    /**
     * One line per shop across the 24 hours, as ready-to-draw SVG geometry
     * (viewBox 960×280). Values below zero (refund-heavy hours) are drawn at
     * the baseline; the tooltip still shows the real figure.
     *
     * @param  array<int, array<int, float>>  $hourly  hour => shop id => net amount
     * @param  array<int, string>  $colors  shop id => colour
     * @param  array<int, string>  $shops  shop id => name
     * @return array<string, mixed>
     */
    private function lineChart(array $hourly, array $colors, array $shops): array
    {
        $width = 960;
        $height = 280;
        [$left, $right, $top, $bottom] = [64, 16, 12, 28];
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $max = 0.0;
        foreach ($hourly as $byShop) {
            foreach ($byShop as $value) {
                $max = max($max, (float) $value);
            }
        }
        $max = $this->niceCeiling($max);

        $x = fn (int $h) => round($left + $h * $plotW / 23, 1);
        $y = fn (float $v) => round($top + $plotH * (1 - ($max > 0 ? max($v, 0) / $max : 0)), 1);

        $lines = [];
        foreach ($colors as $shopId => $color) {
            $points = [];
            $dots = [];
            for ($h = 0; $h < 24; $h++) {
                $value = (float) ($hourly[$h][$shopId] ?? 0);
                $points[] = $x($h).','.$y($value);
                if ($value != 0.0) {
                    $dots[] = [
                        'x' => $x($h),
                        'y' => $y($value),
                        'tip' => ($shops[$shopId] ?? '#'.$shopId).' · '.sprintf('%02d:00', $h).' — '.number_format($value, 0, '.', ' '),
                    ];
                }
            }
            $lines[] = ['color' => $color, 'points' => implode(' ', $points), 'dots' => $dots];
        }

        $ticks = [];
        foreach ([0, 0.25, 0.5, 0.75, 1] as $f) {
            $ticks[] = ['y' => $y($max * $f), 'label' => number_format($max * $f, 0, '.', ' ')];
        }

        $xLabels = [];
        for ($h = 0; $h < 24; $h++) {
            $xLabels[] = ['x' => $x($h), 'label' => sprintf('%02d', $h)];
        }

        return [
            'width' => $width,
            'height' => $height,
            'left' => $left,
            'right' => $width - $right,
            'baseline' => $top + $plotH,
            'lines' => $lines,
            'ticks' => $ticks,
            'xLabels' => $xLabels,
        ];
    }

    /** Round up to a 1/2/5 × 10ⁿ value so the y-axis ticks are readable. */
    private function niceCeiling(float $value): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        $magnitude = 10 ** floor(log10($value));
        foreach ([1, 2, 5, 10] as $step) {
            if ($value <= $step * $magnitude) {
                return (float) ($step * $magnitude);
            }
        }

        return (float) (10 * $magnitude);
    }

    private function parsedDate(): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $this->date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
