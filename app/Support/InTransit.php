<?php

namespace App\Support;

use App\Enums\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which orders count as "goods on the way". An order is in transit while it is in OrderStatus::open() (a received
 * order is `fulfilled`, so it drops out at once) AND it was confirmed at most `inventory.in_transit_max_days` ago
 * (delivery is normally the same day; an order nobody confirmed receipt of must not distort the forecast forever;
 * orders.confirmed_at, created_at for old rows without it).
 *
 * An order waiting for admin approval (orders.review_status = pending) does NOT count: nothing was sent to 1C, so
 * nothing is on its way until it is approved (it then counts from its confirmation time).
 *
 * Warehouse side: the warehouse quantity comes from the 1C stock snapshot of its latest stock_date, which already
 * reflects goods shipped BEFORE that snapshot. Conservative assumption: only orders confirmed on or after the START of
 * that day are subtracted from it (this may subtract an order shipped earlier the same day = understates the
 * warehouse, never overstates it).
 */
class InTransit
{
    public static function maxDays(): int
    {
        return max(1, (int) config('inventory.in_transit_max_days', 1));
    }

    /** Oldest confirmation time that still counts. */
    public static function cutoff(?Carbon $now = null): Carbon
    {
        return ($now ?? now())->copy()->subDays(self::maxDays());
    }

    /**
     * Start of the day of the warehouse's latest stock snapshot, or null when it has none.
     */
    public static function warehouseSince(?int $warehouseId): ?Carbon
    {
        if ($warehouseId === null) {
            return null;
        }
        $date = DB::table('stocks')->where('shop_id', $warehouseId)->max('stock_date');

        return $date === null ? null : Carbon::parse(substr((string) $date, 0, 10))->startOfDay();
    }

    /**
     * Restricts a query over `orders as $alias` to open orders inside the in-transit window.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  Carbon|null  $since  also require a confirmation at or after this moment (warehouse rule)
     * @return \Illuminate\Database\Query\Builder
     */
    public static function apply($query, string $alias = 'o', ?Carbon $since = null)
    {
        $from = self::cutoff();
        if ($since !== null && $since->gt($from)) {
            $from = $since;
        }

        // An order waiting for an admin's approval (review_status pending) is not sent to 1C yet: not on its way.
        // Older pending_approval rows (review_status null) keep counting.
        return $query->whereIn($alias.'.status', OrderStatus::open())
            ->where(function ($q) use ($alias) {
                $q->whereNull($alias.'.review_status')->orWhere($alias.'.review_status', '!=', 'pending');
            })
            ->whereRaw('COALESCE('.$alias.'.confirmed_at, '.$alias.'.created_at) >= ?', [$from->toDateTimeString()]);
    }
}
