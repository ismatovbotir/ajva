<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of an order (audit trail): who (null = the system), through which channel (link = personal secret
 * link, panel = signed-in pages, system = automatic run), what and when.
 */
class OrderEvent extends Model
{
    public const CHANNEL_LINK = 'link';

    public const CHANNEL_PANEL = 'panel';

    public const CHANNEL_SYSTEM = 'system';

    public const CREATED = 'created';

    public const QTY_CHANGED = 'qty_changed';

    public const LINE_REMOVED = 'line_removed';

    public const LINE_ADDED = 'line_added';

    public const LINES_ADDED = 'lines_added';

    public const CONFIRMED = 'confirmed';

    public const COMMENT = 'comment';

    public $timestamps = false;

    protected $fillable = ['order_id', 'user_id', 'channel', 'action', 'item_id', 'old_qty', 'new_qty', 'note', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function record(Order|int $order, ?int $userId, string $channel, string $action, ?int $itemId = null, ?float $oldQty = null, ?float $newQty = null, ?string $note = null): self
    {
        return self::query()->create([
            'order_id' => $order instanceof Order ? $order->id : $order,
            'user_id' => $userId,
            'channel' => $channel,
            'action' => $action,
            'item_id' => $itemId,
            'old_qty' => $oldQty,
            'new_qty' => $newQty,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
