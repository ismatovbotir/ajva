<?php

namespace App\Livewire;

use App\Services\SalesMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Live sales board for the dashboard: today's successful sale receipts by
 * shop and hour compared with the previous day, plus the day's top items.
 * Polled every 2 minutes from the view (wire:poll.120s). The numbers come
 * from App\Services\SalesMetrics (shared with the Monitor page).
 */
class SalesBoard extends Component
{
    /** Slightly under the poll interval so every poll sees fresh data but concurrent viewers share one computation. */
    private const CACHE_TTL = 50;

    public function render()
    {
        $now = now();

        return view('livewire.sales-board', Cache::remember(
            $this->cacheKey($now),
            self::CACHE_TTL,
            fn () => app(SalesMetrics::class)->board($now->copy()),
        ));
    }

    private function cacheKey(?Carbon $at = null): string
    {
        return 'dashboard.sales-board.'.($at ?? now())->toDateString();
    }
}
