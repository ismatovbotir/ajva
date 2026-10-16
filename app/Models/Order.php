<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const ORIGIN_ORDERING_REPORT = 'ordering_report';

    /** Drafted by the daily orders:auto-generate run (created_by is null). */
    public const ORIGIN_AUTO_SCHEDULE = 'auto_schedule';

    protected $fillable = [
        'shop_id',
        'warehouse_id',
        'created_by',
        'confirmed_by',
        'status',
        'origin',
        'horizon_days',
        'confirmed_at',
        'sync_status',
        'queued_at',
        'synced_at',
        'sync_attempts',
        'sync_error',
        'warnings',
        'telegram_status',
        'telegram_sent_at',
        'telegram_note',
        'telegram_recipients_ok',
        'comment',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'sync_status' => OrderSyncStatus::class,
        'confirmed_at' => 'datetime',
        'queued_at' => 'datetime',
        'synced_at' => 'datetime',
        'telegram_sent_at' => 'datetime',
    ];

    public function isAuto(): bool
    {
        return $this->origin === self::ORIGIN_AUTO_SCHEDULE;
    }

    /** Human number, also the idempotency key sent to 1C. */
    public function number(): string
    {
        return 'ORD-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'warehouse_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function links(): HasMany
    {
        return $this->hasMany(OrderLink::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
