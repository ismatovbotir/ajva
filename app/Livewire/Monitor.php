<?php

namespace App\Livewire;

use App\Services\SalesMetrics;
use App\Support\MonitorSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    /**
     * Set only on the public (no-login) route /monitor/{token}. Locked, so the
     * browser cannot change it; re-validated on every request below.
     */
    #[Locked]
    public ?string $publicToken = null;

    public function mount(?string $token = null): void
    {
        if ($token !== null) {
            $this->publicToken = $token;
        }

        $this->guardPublicToken();
    }

    /** Livewire polls skip the route middleware, so check again after every rehydration. */
    public function hydrate(): void
    {
        $this->guardPublicToken();
    }

    /**
     * A public screen is served only while its token is the current one and
     * the public monitor is enabled; otherwise a plain 404, so an old, wrong
     * or disabled link never reveals whether it ever existed.
     */
    private function guardPublicToken(): void
    {
        if ($this->publicToken !== null || request()->routeIs('monitor.public')) {
            abort_unless(app(MonitorSettings::class)->accepts($this->publicToken), 404);
        }
    }

    public function render()
    {
        $this->guardPublicToken();

        $now = now();
        $public = $this->publicToken !== null;
        $showProfit = ! $public || app(MonitorSettings::class)->showProfit();

        $data = Cache::remember(
            self::cacheKey($now),
            self::CACHE_TTL,
            fn () => app(SalesMetrics::class)->board($now->copy()) + [
                'latest' => $this->latestReceipts($now->copy()->startOfDay(), $now),
            ],
        );

        if (! $showProfit) {
            $data = $this->withoutProfit($data);
        }

        return view('livewire.monitor', $data + [
            'public' => $public,
            'showProfit' => $showProfit,
            'alerts' => Cache::remember('dashboard.monitor.alerts', self::ALERTS_TTL, fn () => $this->stockAlerts()),
            'renderedAt' => $now->timestamp,
            'timezone' => config('app.timezone'),
            'currentHour' => (int) $now->format('G'),
            'dateLabel' => $now->format('d.m.Y'),
        ]);
    }

    /**
     * Drops every profit/cost/margin figure, so none of it reaches the public
     * view (or the browser) when the profit switch is off.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutProfit(array $data): array
    {
        unset($data['profit']);

        $data['trend'] = array_map(function (array $day) {
            unset($day['profit'], $day['profit_h'], $day['profit_label']);

            return $day;
        }, $data['trend']);

        $strip = function (array $items) {
            return array_map(function (array $item) {
                unset($item['profit'], $item['profit_negative']);

                return $item;
            }, $items);
        };
        $data['topItems']['all'] = $strip($data['topItems']['all']);
        $data['topItems']['shops'] = array_map(function (array $tab) use ($strip) {
            $tab['items'] = $strip($tab['items']);

            return $tab;
        }, $data['topItems']['shops']);

        return $data;
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
