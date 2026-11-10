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
        'received_at',
        'received_by',
        'close_reason',
        'review_status',
        'review_note',
        'review_exceptions',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'review_exceptions' => 'array',
        'reviewed_at' => 'datetime',
        'status' => OrderStatus::class,
        'sync_status' => OrderSyncStatus::class,
        'confirmed_at' => 'datetime',
        'queued_at' => 'datetime',
        'synced_at' => 'datetime',
        'telegram_sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    /** close_reason values */
    public const CLOSE_RECEIVED = 'received';

    public const CLOSE_EXPIRED = 'expired';

    public const CLOSE_CANCELLED = 'cancelled';

    /** Confirmed and not yet received by the shop (an expired one can still be received late). */
    public function isAwaitingReceipt(): bool
    {
        return $this->status === OrderStatus::Confirmed && $this->received_at === null;
    }

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /** Sent by the shop with exceptions and waiting for an admin (status pending_approval). */
    public function isAwaitingApproval(): bool
    {
        return $this->status === OrderStatus::PendingApproval && $this->review_status === self::REVIEW_PENDING;
    }

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
