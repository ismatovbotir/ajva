<?php

namespace App\Livewire\Home;

use App\Models\User;
use App\Monitor\Comparison;
use App\Services\AttentionItems;
use App\Services\ReceiptsMetrics;
use App\Services\SalesMetrics;
use App\Support\ProfitAccess;
use App\Support\ShopAccess;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Top of the Home page: how the day is going. Admin: sales, receipts and average check against yesterday and the same
 * weekday last week (same time of day), profit only with ProfitAccess, 8-day trend, top 5 shops, refund / cancelled
 * rate chips and the stock-out count. Operator: the same for his own shops plus today's hourly curve against
 * yesterday. Everything is scoped by ShopAccess, never shows cost for an operator and never a cashier name. Shares
 * ONE cached board with the attention panel (ReceiptsMetrics::cachedBoard).
 */
class Summary extends Component
{
    public function boot(): void
    {
        // Livewire updates skip the route middleware.
        abort_unless(in_array(auth()->user()?->role?->value, ['admin', 'operator'], true), 403);
    }

    public function render()
    {
        /** @var User $user */
        $user = auth()->user();
        $ids = ShopAccess::ids($user);
        $admin = $user->isAdmin();
        $now = now();

        $board = app(ReceiptsMetrics::class)->cachedBoard($now, $ids, false);
        $t = $board['today'];
        $y = $board['yesterday'];
        $w = $board['week'];
        $dayBefore = $w['all'] > 0;

        $cmp = [
            'sum' => Comparison::make((float) $t['sum'], (float) $y['sum'], (float) $w['sum'], (int) $y['count'], (int) $w['count'], $dayBefore),
            'count' => Comparison::make((float) $t['count'], (float) $y['count'], (float) $w['count'], (int) $y['count'], (int) $w['count'], $dayBefore),
            'avg' => Comparison::make((float) $t['avg'], (float) $y['avg'], (float) $w['avg'], (int) $y['count'], (int) $w['count'], $dayBefore),
        ];

        // Shops of the leaderboard: a shop's delta needs 10 receipts in ITS yesterday window (the Comparison rule).
        $shops = collect($board['shops'])->take(5)->map(fn (array $s) => [
            'id' => $s['id'],
            'name' => $s['name'],
            'sum' => $s['today']['sum'],
            'count' => $s['today']['count'],
            'delta' => Comparison::pct($s['today']['sum'], $s['yesterday']['sum'], $s['yesterday']['count']),
        ])->all();

        $withProfit = $admin && ProfitAccess::allowed($user);

        return view('livewire.home.summary', [
            'admin' => $admin,
            'asOf' => $board['asOf'],
            't' => $t,
            'cmp' => $cmp,
            'shops' => $shops,
            'hours' => $admin ? [] : $board['hours'],
            'refundStatus' => Comparison::rateStatus((float) $t['refund_amount_percent'], (int) $t['all']),
            'cancelledStatus' => Comparison::rateStatus((float) $t['cancelled_percent'], (int) $t['all']),
            'trend' => $admin ? $this->trend($now, $ids) : [],
            'profit' => $withProfit ? $this->profit($now, $ids) : null,
            'stockouts' => app(AttentionItems::class)->report($user, $now)['facts']['stockouts'] ?? 0,
        ]);
    }

    /** Last 8 days of sales (no profit), cached with the sales version like the board. */
    private function trend($now, ?array $ids): array
    {
        return Cache::remember(
            ShopAccess::salesKey('home.trend', $now->toDateString(), $ids),
            50,
            fn () => app(SalesMetrics::class)->trend($now->copy()->startOfDay(), $ids, false, 8),
        );
    }

    /** Profit summary (cost-covered lines): only called for a user with ProfitAccess, cached under its own key. */
    private function profit($now, ?array $ids): array
    {
        return Cache::remember(
            ShopAccess::salesKey('home.profit', $now->toDateString().'.'.ProfitAccess::profitKey(true), $ids),
            50,
            function () use ($now, $ids) {
                $w = SalesMetrics::windows($now);

                return app(SalesMetrics::class)->profitSummary($w['today'][0], $w['today'][1], $w['yesterday'][0], $w['yesterday'][1], [], [], $ids, $w['week'][0], $w['week'][1]);
            },
        );
    }
}
