<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barcode extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'gtin',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
