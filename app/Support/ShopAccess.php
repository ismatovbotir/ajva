<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The single place that decides which shops the current user may see.
 *
 * ids() returns null for "unrestricted" (admin, monitor role, guests such as
 * the public wall monitor) or a list of shop ids for an operator - possibly
 * empty, which means "nothing", never "everything".
 */
class ShopAccess
{
    private const VERSION_KEY = 'sales.version';

    /**
     * @return array<int, int>|null null = every shop
     */
    public static function ids(?User $user = null): ?array
    {
        $user ??= auth()->user();

        if ($user === null || $user->role !== UserRole::Operator) {
            return null;
        }

        return $user->shops()->pluck('shops.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public static function restricted(?User $user = null): bool
    {
        return self::ids($user) !== null;
    }

    /** True for an operator who has no shop assigned (shows the hint, sees no data). */
    public static function hasNone(?User $user = null): bool
    {
        return self::ids($user) === [];
    }

    public static function allows(?int $shopId, ?User $user = null): bool
    {
        $ids = self::ids($user);

        return $ids === null || ($shopId !== null && in_array($shopId, $ids, true));
    }

    /** Plain 404 for a shop outside the scope, so its existence is not revealed. */
    public static function authorize(?int $shopId): void
    {
        abort_unless(self::allows($shopId), 404);
    }

    /**
     * Restrict an Eloquent/query builder to the current user's visible shops
     * on the given column ('shop_id', 'receipts.shop_id', 'shops.id', ...).
     */
    public static function restrict($query, string $column)
    {
        return self::restrictTo($query, $column, self::ids());
    }

    /** Same, for an explicit id list (null = unrestricted); used by cached/service code. */
    public static function restrictTo($query, string $column, ?array $ids)
    {
        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /** Cache-key fragment: 'all' or a hash of the sorted ids, so scopes never share entries. */
    public static function scopeKey(?array $ids): string
    {
        if ($ids === null) {
            return 'all';
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids === [] ? 'none' : 's'.md5(implode(',', $ids));
    }

    /** Version counter folded into sales cache keys; see bumpSalesVersion(). */
    public static function salesVersion(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    /**
     * Invalidates every scope variant of the sales caches at once (the file
     * cache driver has no tags): old keys simply stop being read.
     */
    public static function bumpSalesVersion(): void
    {
        Cache::increment(self::VERSION_KEY);
    }

    /** Key for a per-day sales cache entry that varies by scope and sales version. */
    public static function salesKey(string $prefix, string $suffix, ?array $ids): string
    {
        return $prefix.'.v'.self::salesVersion().'.'.self::scopeKey($ids).'.'.$suffix;
    }
}
