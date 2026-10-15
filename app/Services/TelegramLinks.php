<?php

namespace App\Services;

use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ties a Telegram chat to a user with a one-time code: the admin creates a code in the Users modal, the person opens
 * t.me/<bot>?start=<code> and presses Start, the poller (TelegramUpdates) redeems the code and stores the chat id on
 * the user.
 */
class TelegramLinks
{
    public const TTL_MINUTES = 15;

    /** A fresh code for the user; earlier unused codes of the same user stop working. */
    public function issue(User $user): TelegramLinkCode
    {
        TelegramLinkCode::query()->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);

        return TelegramLinkCode::query()->create([
            'user_id' => $user->id,
            'code' => Str::random(12),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ]);
    }

    public static function deepLink(string $code): ?string
    {
        $bot = Telegram::botUsername();

        return $bot === '' ? null : 'https://t.me/'.$bot.'?start='.$code;
    }

    /**
     * Redeems a code for a chat. Idempotent: a used code or an already-linked chat is never applied twice.
     *
     * @return string linked | invalid | used | expired | inactive | chat_taken
     */
    public function redeem(string $code, string $chatId): string
    {
        return DB::transaction(function () use ($code, $chatId) {
            $row = TelegramLinkCode::query()->where('code', $code)->lockForUpdate()->first();
            if ($row === null) {
                return 'invalid';
            }
            if ($row->used_at !== null) {
                return 'used';
            }
            if (! $row->expires_at->isFuture()) {
                return 'expired';
            }

            $user = User::query()->find($row->user_id);
            if ($user === null || ! $user->is_active) {
                return 'inactive';
            }
            if (User::query()->where('telegram_chat_id', $chatId)->whereKeyNot($user->id)->exists()) {
                return 'chat_taken';
            }

            $user->forceFill(['telegram_chat_id' => $chatId])->save();
            $row->forceFill(['used_at' => now()])->save();

            return 'linked';
        });
    }
}
