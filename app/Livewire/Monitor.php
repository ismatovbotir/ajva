<?php

namespace App\Livewire;

use App\Services\SalesMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Wall-display ("kiosk") view of the current day's receipts. Polled every
 * 30 seconds; the computed data is cached for ~25 seconds so any number of
 * screens share one computation, and the cache is dropped when a receipt is
 * ingested (see ProcessReceiptIngestion).
 */
#[Layout('components.layouts.monitor', ['title' => 'Monitor'])]
class Monitor extends Component
{
    private const CACHE_TTL = 25;

    private const ALERTS_TTL = 300;

    private const TICKER_ROWS = 8;

    public static function cacheKey(?Carbon $at = null): string
    {
        return 'dashboard.monitor.'.($at ?? now())->toDateString();
    }

    public function render()
    {
        $now = now();

        $data = Cache::remember(
            self::cacheKey($now),
            self::CACHE_TTL,
            fn () => app(SalesMetrics::class)->board($now->copy()) + [
                'latest' => $this->latestReceipts($now->copy()->startOfDay(), $now),
            ],
        );

        return view('livewire.monitor', $data + [
            'alerts' => Cache::remember('dashboard.monitor.alerts', self::ALERTS_TTL, fn () => $this->stockAlerts()),
            'renderedAt' => $now->timestamp,
            'timezone' => config('app.timezone'),
            'currentHour' => (int) $now->format('G'),
            'dateLabel' => $now->format('d.m.Y'),
        ]);
    }

    /**
     * Newest receipts of the day (refunds included, flagged), with their payment types.
     *
     * @return array<int, array<string, mixed>>
     */
    private function latestReceipts(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('receipts')
            ->join('shops', 'shops.id', '=', 'receipts.shop_id')
            ->where('receipts.active', true)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->orderByDesc('receipts.created_at')
            ->orderByDesc('receipts.id')
            ->limit(self::TICKER_ROWS)
            ->get(['receipts.id', 'receipts.created_at', 'receipts.total', 'receipts.sell', 'shops.id as shop_id', 'shops.name as shop_name']);

        $payments = DB::table('receipt_payments')
            ->whereIn('receipt_id', $rows->pluck('id'))
            ->orderByDesc('value')
            ->get(['receipt_id', 'payment'])
            ->groupBy('receipt_id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'time' => Carbon::parse($r->created_at)->format('H:i:s'),
            'shop' => (string) $r->shop_name,
            'shop_id' => (int) $r->shop_id,
            'total' => number_format((float) $r->total, 0, '.', ' '),
            'refund' => ! $r->sell,
            'payment' => ($payments[$r->id] ?? collect())->pluck('payment')->unique()->implode(', '),
        ])->all();
    }

    /**
     * @return array{below_min: int, out_of_stock: int}
     */
    private function stockAlerts(): array
    {
        $pairs = fn () => DB::table('stocks')->join('item_order_rules', function ($join) {
            $join->on('item_order_rules.item_id', '=', 'stocks.item_id')
                ->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
        });

        return [
            'below_min' => $pairs()->whereColumn('stocks.qty', '<=', 'item_order_rules.min')->count(),
            'out_of_stock' => $pairs()->where('stocks.qty', '<=', 0)->where('item_order_rules.min', '>', 0)->count(),
        ];
    }
}
