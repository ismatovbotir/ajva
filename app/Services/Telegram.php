<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Minimal Telegram Bot API client (sendMessage with an optional inline URL button). The bot token comes from
 * config('services.telegram.bot_token') (env TELEGRAM_BOT_TOKEN) and is never logged or returned in an error.
 */
class Telegram
{
    public function configured(): bool
    {
        return $this->token() !== '';
    }

    private function token(): string
    {
        return trim((string) config('services.telegram.bot_token'));
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function send(string $chatId, string $text, ?string $buttonText = null, ?string $buttonUrl = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($buttonText !== null && $buttonUrl !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => [[['text' => $buttonText, 'url' => $buttonUrl]]]];
        }

        try {
            $response = Http::asJson()->timeout(10)->post($this->url('sendMessage'), $payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $this->clean($e->getMessage())];
        }

        if ($response->successful() && $response->json('ok') === true) {
            return ['ok' => true, 'error' => null];
        }

        $detail = (string) ($response->json('description') ?? 'HTTP '.$response->status());

        return ['ok' => false, 'error' => $this->clean($detail)];
    }

    /**
     * One getUpdates call (messages only, no long polling).
     *
     * @return array{ok: bool, updates: array<int, array<string, mixed>>, error: ?string}
     */
    public function getUpdates(?int $offset, int $limit = 100): array
    {
        $payload = ['timeout' => 0, 'limit' => $limit, 'allowed_updates' => ['message']];
        if ($offset !== null) {
            $payload['offset'] = $offset;
        }

        try {
            $response = Http::asJson()->timeout(20)->post($this->url('getUpdates'), $payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'updates' => [], 'error' => $this->clean($e->getMessage())];
        }

        if ($response->successful() && $response->json('ok') === true) {
            return ['ok' => true, 'updates' => (array) $response->json('result', []), 'error' => null];
        }

        return ['ok' => false, 'updates' => [], 'error' => $this->clean((string) ($response->json('description') ?? 'HTTP '.$response->status()))];
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot'.$this->token().'/'.$method;
    }

    public static function botUsername(): string
    {
        return ltrim(trim((string) config('services.telegram.bot_username')), '@');
    }

    /** Messages never contain the token (the API URL does, and connection errors quote it). */
    private function clean(string $text): string
    {
        $token = $this->token();

        return mb_substr($token === '' ? $text : str_replace($token, '***', $text), 0, 300);
    }
}
