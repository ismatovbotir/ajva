<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Last run of the Telegram console commands ("poll", "send"), kept as JSON in the `settings` table under
 * "telegram.status.{name}" so the Telegram > Status page can show whether the scheduler really runs them.
 */
class TelegramStatus
{
    /** @param array<string, mixed> $data */
    public static function record(string $name, array $data): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'telegram.status.'.$name],
            ['value' => json_encode($data + ['at' => now()->toIso8601String()])]
        );
    }

    /** @return array<string, mixed>|null with `at` as Carbon */
    public static function get(string $name): ?array
    {
        $raw = DB::table('settings')->where('key', 'telegram.status.'.$name)->value('value');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ! isset($data['at'])) {
            return null;
        }
        $data['at'] = Carbon::parse($data['at']);

        return $data;
    }
}
