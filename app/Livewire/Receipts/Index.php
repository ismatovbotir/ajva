<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
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

        $analytics = Cache::remember(
            'receipts.analytics.'.$from->toDateString(),
            self::ANALYTICS_TTL,
            fn () => $this->buildAnalytics($from, $to),
        );

        return view('livewire.receipts.index', [
            'receipts' => Receipt::query()
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
    private function buildAnalytics(Carbon $from, Carbon $to): array
    {
        $shops = DB::table('shops')->pluck('name', 'id')->all();

        // Refunds count negatively so the totals reflect net sales.
        $sign = fn ($sell) => $sell ? 1 : -1;

        // Shop totals by payment type.
        $paymentRows = DB::table('receipt_payments')
            ->join('receipts', 'receipts.id', '=', 'receipt_payments.receipt_id')
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
        $receiptRows = DB::table('receipts')
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

        $hourTotals = array_map(fn ($h) => array_sum($h), $hourly);
        $maxHour = $hourTotals === [] ? 0 : max(max($hourTotals), 0);

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $segments = [];
            foreach ($hourly[$h] ?? [] as $shopId => $amount) {
                if ($amount <= 0 || $maxHour <= 0) {
                    continue;
                }
                $segments[] = [
                    'name' => $shops[$shopId] ?? '#'.$shopId,
                    'color' => $colors[$shopId],
                    'percent' => $amount / $maxHour * 100,
                    'label' => number_format($amount, 0, '.', ' '),
                ];
            }
            $total = $hourTotals[$h] ?? 0;
            $hours[] = [
                'hour' => sprintf('%02d', $h),
                'total' => $total,
                'total_label' => number_format($total, 0, '.', ' '),
                'segments' => $segments,
            ];
        }

        return [
            'paymentTypes' => $paymentTypes,
            'paymentTable' => $paymentTable,
            'columnTotals' => $columnTotals,
            'grandTotal' => array_sum($columnTotals),
            'hours' => $hours,
            'legend' => $legend,
            'summary' => [
                'total' => array_sum($shopTotals),
                'count' => $count,
            ],
        ];
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
