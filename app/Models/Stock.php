<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class Stock extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'shop_id',
        'qty',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * The unique(item_id, shop_id) rule for the shop_id field, shared by every
     * form that writes to this table (the standalone Stocks screen and the
     * Item show page's Stock tab) so the composite-uniqueness check and its
     * friendly error message stay in one place.
     */
    public static function shopUniqueRule(?int $itemId, ?int $ignoreId = null): Unique
    {
        return Rule::unique('stocks', 'shop_id')
            ->where(fn ($query) => $query->where('item_id', $itemId))
            ->ignore($ignoreId);
    }
}
