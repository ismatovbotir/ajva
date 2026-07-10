<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_id',
        'shop_id',
        'number',
        'client',
        'cashier',
        'total',
        'discount',
        'active',
        'sell',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'discount' => 'decimal:2',
        'active' => 'boolean',
        'sell' => 'boolean',
    ];

    public function pos(): BelongsTo
    {
        return $this->belongsTo(Pos::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceiptPayment::class);
    }
}
