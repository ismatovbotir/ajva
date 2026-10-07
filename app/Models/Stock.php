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

    public function scopeCurrent($query)
    {
        return static::applyCurrent($query, $this->getTable());
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
