<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramMessage extends Model
{
    protected $fillable = [
        'update_id', 'telegram_id', 'direction', 'chat_id', 'chat_type', 'from_id', 'message_id',
        'type', 'status', 'error', 'text', 'sent_by', 'order_id', 'user_id', 'sent_at',
    ];

    protected $casts = [
        'update_id' => 'integer',
        'chat_id' => 'integer',
        'from_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function telegram(): BelongsTo
    {
        return $this->belongsTo(Telegram::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
