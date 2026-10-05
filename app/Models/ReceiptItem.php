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
        'art',
        'name',
        'class_code',
        'package_code',
        'line_barcode',
        'storno',
        'sum',
        'sum_r',
        'sum_wd',
        'sum_wt',
        'labels',
    ];

    protected $casts = [
        'active' => 'boolean',
        'qty' => 'decimal:3',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'receipt_active' => 'boolean',
        'receipt_sell' => 'boolean',
        'storno' => 'boolean',
        'sum' => 'decimal:2',
        'sum_r' => 'decimal:2',
        'sum_wd' => 'decimal:2',
        'sum_wt' => 'decimal:2',
        'labels' => 'array',
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
