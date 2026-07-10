<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemOrderRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'shop_id',
        'min',
        'max',
    ];

    protected $casts = [
        'min' => 'decimal:3',
        'max' => 'decimal:3',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
