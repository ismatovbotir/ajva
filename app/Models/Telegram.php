<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Telegram extends Model
{
    protected $fillable = ['tg_id', 'username', 'first_name', 'last_name', 'language_code', 'is_bot', 'last_message_at'];

    protected $casts = [
        'tg_id' => 'integer',
        'is_bot' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    public function displayName(): string
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $name !== '' ? $name : ($this->username ? '@'.$this->username : '#'.$this->tg_id);
    }
}
