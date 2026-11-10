<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'shop_id',
        'qty',
        'stock_date',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'stock_date' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::creating(function (Stock $stock) {
            if ($stock->stock_date === null) {
                $stock->stock_date = now()->toDateString();
            }
        });
    }

    /**
     * The one definition of "current stock": `stocks` keeps a daily history,
     * and the current row for an (item, shop) pair is the one with the greatest
     * stock_date (a pair absent from the latest sync keeps its last known qty).
     * Correlated MAX lookup served by unique(item_id, shop_id, stock_date).
     *
     * Works on Eloquent and DB::table() builders alike; pass the table alias
     * if `stocks` is aliased in the outer query.
     */
    public static function applyCurrent($query, string $table = 'stocks')
    {
        return $query->whereRaw(
            "{$table}.stock_date = (select max(s_cur.stock_date) from stocks as s_cur "
            ."where s_cur.item_id = {$table}.item_id and s_cur.shop_id = {$table}.shop_id)"
        );
    }

    /**
     * EXPLICIT ZERO (config inventory.explicit_zero_stock, env EXPLICIT_ZERO_STOCK, default true).
     *
     * 1C reports a zero quantity explicitly, so the app never guesses from a missing row:
     *  - OUT of stock = a REAL quantity <= 0 in the shop's latest sync.
     *  - REPORTED pair = its current row (applyCurrent) has stock_date = R(shop), the shop's latest stock_date
     *    (latestDates()). A pair whose current row is older than R, or that has no row, is NOT REPORTED
     *    (state `no_data`): not in stock, not out of stock, not counted as zero, no order suggested from it.
     * Every reader uses applyReported() (SQL), latestDates() / isReported() (PHP): ONE definition.
     * With the flag off all three are no-ops / "reported" and the legacy inference (a pair keeps its last quantity) returns.
     */
    public static function explicitZero(): bool
    {
        return (bool) config('inventory.explicit_zero_stock', true);
    }

    /**
     * Restricts to pairs REPORTED by the shop's latest sync: use together with applyCurrent (stock_date = the greatest
     * of the pair AND equal to the greatest of the shop). No-op with explicit zero off.
     */
    public static function applyReported($query, string $table = 'stocks')
    {
        if (! static::explicitZero()) {
            return $query;
        }

        return $query->whereRaw(
            "{$table}.stock_date = (select max(s_rep.stock_date) from stocks as s_rep where s_rep.shop_id = {$table}.shop_id)"
        );
    }

    /**
     * The opposite of applyReported (use with applyCurrent): pairs whose current row is older than the shop's latest
     * sync = "not reported by 1C". Matches nothing with explicit zero off.
     */
    public static function applyNotReported($query, string $table = 'stocks')
    {
        if (! static::explicitZero()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw(
            "{$table}.stock_date < (select max(s_rep.stock_date) from stocks as s_rep where s_rep.shop_id = {$table}.shop_id)"
        );
    }

    /**
     * R per shop: the latest stock_date of the shop's own rows (one grouped query). Shops without rows are absent.
     *
     * @param  array<int, int>|null  $shopIds  null = every shop
     * @return array<int, string> shop id => 'Y-m-d'
     */
    public static function latestDates(?array $shopIds = null, ?string $asOf = null): array
    {
        $query = \Illuminate\Support\Facades\DB::table('stocks')->groupBy('shop_id')->selectRaw('shop_id, max(stock_date) as latest');
        if ($shopIds !== null) {
            $query->whereIn('shop_id', $shopIds);
        }
        if ($asOf !== null) {
            $query->where('stock_date', '<=', $asOf);
        }

        return $query->pluck('latest', 'shop_id')->map(fn ($d) => substr((string) $d, 0, 10))->all();
    }

    /** Is a current row dated $date reported by the sync whose latest day is $latest? Always true with explicit zero off. */
    public static function isReported(?string $date, ?string $latest): bool
    {
        if (! static::explicitZero()) {
            return true;
        }

        return $date !== null && $latest !== null && substr($date, 0, 10) >= $latest;
    }

    /**
     * Stock "as of" a day, for reports with a date picker: for each (item, shop)
     * the row with the greatest stock_date on or before $date (carry-forward, same
     * as applyCurrent but capped at the chosen day). A pair with no snapshot yet by
     * that day matches no row, so the report shows it as having no stock.
     *
     * Works on Eloquent, DB::table() and join-clause builders.
     */
    public static function applyAsOf($query, string $date, string $table = 'stocks')
    {
        return $query->whereRaw(
            "{$table}.stock_date = (select max(s_asof.stock_date) from stocks as s_asof "
            ."where s_asof.item_id = {$table}.item_id and s_asof.shop_id = {$table}.shop_id "
            .'and s_asof.stock_date <= ?)',
            [$date]
        );
    }

    public function scopeCurrent($query)
    {
        return static::applyCurrent($query, $this->getTable());
    }

    /** Pairs reported by the shop's latest sync (explicit zero; no-op with the flag off). Use after current(). */
    public function scopeReported($query)
    {
        return static::applyReported($query, $this->getTable());
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
