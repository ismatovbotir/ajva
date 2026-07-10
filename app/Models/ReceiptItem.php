<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_id',
        'item_id',
        'active',
        'qty',
        'price',
        'discount',
        'total',
        'receipt_active',
        'receipt_sell',
    ];

    protected $casts = [
        'active' => 'boolean',
        'qty' => 'decimal:3',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'receipt_active' => 'boolean',
        'receipt_sell' => 'boolean',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
