<?php

namespace App\Services;

use App\Models\TelegramMessage;
use App\Models\Telegram as TelegramAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls the bot's new messages with getUpdates (no webhook) and stores the senders (telegrams) and the
 * messages (telegram_messages: chat_id, from_id, type, text). The offset (last update_id + 1) is kept in
 * `settings` key "telegram.offset" and saved after every single update, so a crash never replays or loses one.
 */
class TelegramUpdates
{
    private const OFFSET_KEY = 'telegram.offset';

    public function __construct(private Telegram $telegram)
    {
    }

    /**
     * @return array{status: string, messages: int, error: ?string} status: ok | skipped | no_token | error
     */
    public function poll(): array
    {
        if (! $this->telegram->configured()) {
            return ['status' => 'no_token', 'messages' => 0, 'error' => null];
        }

        $lock = Cache::lock('telegram.poll', 55);
        if (! $lock->get()) {
            return ['status' => 'skipped', 'messages' => 0, 'error' => null];
        }

        try {
            $offset = $this->offset();
            $result = $this->telegram->getUpdates($offset);
            if (! $result['ok']) {
                return ['status' => 'error', 'messages' => 0, 'error' => $result['error']];
            }

            $stored = 0;
            foreach ($result['updates'] as $update) {
                $updateId = (int) ($update['update_id'] ?? 0);
                if ($updateId <= 0 || ($offset !== null && $updateId < $offset)) {
                    continue;
                }

                try {
                    if ($this->store($update)) {
                        $stored++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }

                $this->saveOffset($updateId + 1);
                $offset = $updateId + 1;
            }

            return ['status' => 'ok', 'messages' => $stored, 'error' => null];
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $update */
    private function store(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ! isset($message['chat']['id'])) {
            return false;
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : null;
        $sentAt = isset($message['date']) ? Carbon::createFromTimestamp((int) $message['date']) : now();

        return DB::transaction(function () use ($update, $message, $from, $sentAt) {
            $user = null;
            if ($from !== null && isset($from['id'])) {
                $user = TelegramAccount::query()->updateOrCreate(
                    ['tg_id' => (int) $from['id']],
                    [
                        'username' => $from['username'] ?? null,
                        'first_name' => $from['first_name'] ?? null,
                        'last_name' => $from['last_name'] ?? null,
                        'language_code' => $from['language_code'] ?? null,
                        'is_bot' => (bool) ($from['is_bot'] ?? false),
                        'last_message_at' => $sentAt,
                    ]
                );
            }

            $row = TelegramMessage::query()->firstOrCreate(
                ['update_id' => (int) $update['update_id']],
                [
                    'telegram_id' => $user?->id,
                    'direction' => 'in',
                    'chat_id' => (int) $message['chat']['id'],
                    'chat_type' => $message['chat']['type'] ?? null,
                    'from_id' => isset($from['id']) ? (int) $from['id'] : null,
                    'message_id' => $message['message_id'] ?? null,
                    'type' => $this->type($message),
                    'text' => $this->text($message),
                    'sent_at' => $sentAt,
                ]
            );

            return $row->wasRecentlyCreated;
        });
    }

    /** @param array<string, mixed> $message */
    private function text(array $message): ?string
    {
        $text = $message['text'] ?? $message['caption'] ?? null;

        return is_string($text) ? mb_substr($text, 0, 4000) : null;
    }

    /** @param array<string, mixed> $message */
    private function type(array $message): string
    {
        foreach (['text', 'photo', 'document', 'voice', 'video', 'audio', 'sticker', 'location', 'contact'] as $type) {
            if (isset($message[$type])) {
                return $type;
            }
        }

        return 'other';
    }

    private function offset(): ?int
    {
        $value = DB::table('settings')->where('key', self::OFFSET_KEY)->value('value');

        return is_numeric($value) ? (int) $value : null;
    }

    private function saveOffset(int $offset): void
    {
        DB::table('settings')->updateOrInsert(['key' => self::OFFSET_KEY], ['value' => (string) $offset]);
    }
}
