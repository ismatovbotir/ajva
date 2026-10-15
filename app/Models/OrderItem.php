<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'item_id',
        'qty',
        'suggested_qty',
        'horizon_days',
        'est',
        'min',
        'speed',
        'cover_days',
        'added_manually',
        'flags',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'suggested_qty' => 'decimal:3',
        'added_manually' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
