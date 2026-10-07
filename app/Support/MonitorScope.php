<?php

namespace App\Support;

use App\Models\Monitor;
use Illuminate\Support\Carbon;

/**
 * Everything a monitor type needs to compute its data, resolved once per
 * request by App\Livewire\MonitorScreen. A type's data method must only read
 * shop-bound data through $shopIds and must not compute profit/cost unless
 * $withProfit is true.
 */
class MonitorScope
{
    /**
     * @param  array<int, int>|null  $shopIds  shops to show; null = every shop, [] = none (show nothing)
     */
    public function __construct(
        public readonly Monitor $monitor,
        public readonly ?array $shopIds,
        public readonly bool $withProfit,
        public readonly bool $public,
        public readonly Carbon $now,
    ) {}

    /**
     * Cache key unique per monitor, day, shop scope, profit variant and the
     * shared sales version (bumped on receipt ingestion, which therefore
     * invalidates every monitor at once).
     */
    public function cacheKey(string $name): string
    {
        return ShopAccess::salesKey(
            'monitor.'.$name.'.m'.$this->monitor->id,
            $this->now->toDateString().'.'.ProfitAccess::profitKey($this->withProfit),
            $this->shopIds,
        );
    }
}
