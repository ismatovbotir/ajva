<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives the bot's messages by polling getUpdates (no webhook, no public URL). Only private-chat "/start <code>"
 * messages matter: they redeem a link code (TelegramLinks) and the bot answers. The last processed update_id is stored
 * in the settings table (key telegram.offset, survives restarts); getUpdates is asked for offset = last + 1 and the
 * offset is advanced AFTER every single update, also for ignored, unknown or failing ones, so nothing is processed
 * twice and one bad update cannot block the queue. A cache lock keeps two pollers from working at once.
 */
class TelegramUpdates
{
    private const OFFSET_KEY = 'telegram.offset';

    private const LOCK = 'telegram.poll';

    public function __construct(private readonly Telegram $telegram, private readonly TelegramLinks $links) {}

    /**
     * One getUpdates pass.
     *
     * @return array{handled: int, error: ?string, skipped: bool} skipped = another poller holds the lock; error = what went wrong (the offset is untouched)
     */
    public function poll(int $timeout = 0): array
    {
        if (! $this->telegram->configured()) {
            return ['handled' => 0, 'error' => 'no_token', 'skipped' => false];
        }

        $lock = Cache::lock(self::LOCK, $timeout + 60);
        if (! $lock->get()) {
            return ['handled' => 0, 'error' => null, 'skipped' => true];
        }

        try {
            $offset = $this->offset();
            $result = $this->telegram->getUpdates($offset === null ? null : $offset + 1, $timeout);

            // A webhook blocks getUpdates (409): remove it once and ask again.
            if (! $result['ok'] && $result['status'] === 409) {
                $this->telegram->deleteWebhook();
                $result = $this->telegram->getUpdates($offset === null ? null : $offset + 1, $timeout);
            }
            if (! $result['ok']) {
                Log::warning('Telegram getUpdates failed', ['status' => $result['status'], 'error' => $result['error']]);

                return ['handled' => 0, 'error' => $result['status'] === 409 ? 'conflict' : (string) $result['error'], 'skipped' => false];
            }

            $updates = collect($result['updates'])->filter(fn ($u) => is_array($u) && isset($u['update_id']))->sortBy('update_id')->values();
            $handled = 0;
            foreach ($updates as $update) {
                $id = (int) $update['update_id'];
                if ($offset !== null && $id <= $offset) {
                    continue;   // replayed
                }
                try {
                    $this->handle($update);
                    $handled++;
                } catch (Throwable $e) {
                    report($e);
                }
                $this->saveOffset($id);
                $offset = $id;
            }

            return ['handled' => $handled, 'error' => null, 'skipped' => false];
        } finally {
            $lock->release();
        }
    }

    public function offset(): ?int
    {
        $value = DB::table('settings')->where('key', self::OFFSET_KEY)->value('value');

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function saveOffset(int $id): void
    {
        $updated = DB::table('settings')->where('key', self::OFFSET_KEY)->update(['value' => (string) $id, 'updated_at' => now()]);
        if ($updated === 0) {
            DB::table('settings')->insert(['key' => self::OFFSET_KEY, 'value' => (string) $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param  array<string, mixed>  $update */
    private function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ! isset($message['chat']['id'])) {
            return;
        }
        $chatId = (string) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? ''));

        if (! preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/', $text, $m)) {
            return;
        }
        $code = $m[1] ?? '';
        if ($code === '' || ! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $code)) {
            $this->telegram->send($chatId, __('To connect Telegram, open the link your administrator gave you (Settings > Users > Connect Telegram).'));

            return;
        }

        $status = $this->links->redeem($code, $chatId);
        if ($status === 'chat_taken') {
            Log::warning('Telegram link refused: the chat already belongs to another user', ['chat' => $chatId]);
        }

        $this->telegram->send($chatId, match ($status) {
            'linked' => __('Telegram is connected. Order drafts for your shops will arrive here.'),
            'chat_taken' => __('This Telegram account is already connected to another user. Ask your administrator.'),
            'expired' => __('This link has expired. Ask your administrator for a new one.'),
            'used', 'invalid' => __('This link is not valid any more. Ask your administrator for a new one.'),
            default => __('This account cannot be connected. Ask your administrator.'),
        });
    }
}
