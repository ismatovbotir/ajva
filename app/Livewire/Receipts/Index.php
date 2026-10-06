<?php

namespace App\Livewire\Receipts;

use App\Models\Receipt;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Receipts'])]
class Index extends Component
{
    use WithPagination;

    private const COLORS = [
        '#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#c23a3a', '#a13a7a',
    ];

    private const COLOR_OTHER = '#898781';

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

        $this->resetPage();
    }

    public function render()
    {
        $day = $this->parsedDate() ?? now()->startOfDay();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $shops = Shop::query()->orderBy('name')->pluck('name', 'id')->all();

        $receiptQuery = fn () => Receipt::query()
            ->where('active', true)
            ->whereBetween('created_at', [$from, $to]);

        // Refunds count negatively so the totals reflect net sales.
        $sign = fn ($sell) => $sell ? 1 : -1;

        // Shop totals by payment type.
        $paymentRows = ReceiptPayment::query()
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

        // Shop totals by hour.
        $hourly = [];
        $shopTotals = [];
        $receiptQuery()->get(['shop_id', 'sell', 'total', 'created_at'])->each(
            function ($r) use (&$hourly, &$shopTotals, $sign) {
                $amount = $sign($r->sell) * (float) $r->total;
                $hour = (int) $r->created_at->format('G');
                $hourly[$hour][$r->shop_id] = ($hourly[$hour][$r->shop_id] ?? 0) + $amount;
                $shopTotals[$r->shop_id] = ($shopTotals[$r->shop_id] ?? 0) + $amount;
            }
        );

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

        $summary = [
            'total' => array_sum($shopTotals),
            'count' => $receiptQuery()->count(),
        ];

        return view('livewire.receipts.index', [
            'receipts' => Receipt::query()
                ->with(['pos', 'shop'])
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id', 'desc')
                ->paginate(15),
            'paymentTypes' => $paymentTypes,
            'paymentTable' => $paymentTable,
            'columnTotals' => $columnTotals,
            'grandTotal' => array_sum($columnTotals),
            'hours' => $hours,
            'legend' => $legend,
            'summary' => $summary,
        ]);
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
