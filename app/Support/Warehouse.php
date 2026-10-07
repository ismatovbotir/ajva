<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * The main warehouse is one of the shops, chosen in Settings > Warehouse and
 * stored as JSON under the `settings` key "warehouse" ({"shop_id": 3}).
 */
class Warehouse
{
    private const KEY = 'warehouse';

    public static function shopId(): ?int
    {
        $raw = DB::table('settings')->where('key', self::KEY)->value('value');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $id = is_array($data) ? ($data['shop_id'] ?? null) : null;

        return is_numeric($id) ? (int) $id : null;
    }

    public static function shop(): ?Shop
    {
        $id = self::shopId();

        return $id === null ? null : Shop::query()->find($id);
    }

    public static function isWarehouse(?int $shopId): bool
    {
        return $shopId !== null && $shopId === self::shopId();
    }

    public static function isConfigured(): bool
    {
        return self::shop() !== null;
    }

    public static function setShopId(?int $shopId): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode(['shop_id' => $shopId]), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
