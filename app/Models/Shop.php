<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'area_m2',
        'map_url',
    ];

    protected $casts = [
        'area_m2' => 'decimal:2',
    ];

    /** Hosts a shop map link may point to (Yandex Maps, incl. short links). */
    public const MAP_HOST_PATTERN = '#^https://([a-z0-9-]+\.)*(yandex\.[a-z]{2,3}(\.[a-z]{2})?|ya\.ru)(/|\?|$)#i';

    public function pos(): HasMany
    {
        return $this->hasMany(Pos::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /** Current stock only (latest stock_date per item), see Stock::scopeCurrent(). */
    public function currentStocks(): HasMany
    {
        return $this->hasMany(Stock::class)->current();
    }

    /** Current stock REPORTED by the shop's latest 1C sync (explicit zero): the figures to count and sum. */
    public function reportedStocks(): HasMany
    {
        return $this->hasMany(Stock::class)->current()->reported();
    }

    public function orderRules(): HasMany
    {
        return $this->hasMany(ItemOrderRule::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
