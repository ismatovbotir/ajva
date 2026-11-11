<?php

namespace App\Mcp\Tools;

use App\Mcp\InvalidArguments;
use App\Mcp\TelegramTool;
use App\Models\TelegramMessage;

class GetTelegramMessage extends TelegramTool
{
    private const CONTEXT = 5;

    public function name(): string
    {
        return 'get_telegram_message';
    }

    public function description(): string
    {
        return 'One Telegram bot message by id (from list_telegram_messages) with the conversation around it: up to '.self::CONTEXT.' earlier and '.self::CONTEXT.' later messages (received and sent) of the same person. Same privacy rules as list_telegram_messages: text is cut and masked, no chat ids, Telegram ids or phone numbers.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Message id.'],
            ],
            'required' => ['id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $id = $this->optionalInt($arguments, 'id', 1);
        $message = $id === null ? null : TelegramMessage::query()->find($id);
        if ($message === null) {
            throw new InvalidArguments('Telegram message was not found.');
        }

        $before = collect();
        $after = collect();
        if ($message->telegram_id !== null) {
            $conversation = TelegramMessage::query()->where('telegram_id', $message->telegram_id)->whereKeyNot($message->id);
            $at = $message->sent_at ?? $message->created_at;
            $before = (clone $conversation)->where(fn ($q) => $q->where('sent_at', '<', $at)->orWhere(fn ($q2) => $q2->where('sent_at', $at)->where('id', '<', $message->id)))
                ->orderByDesc('sent_at')->orderByDesc('id')->limit(self::CONTEXT)->get()->reverse()->values();
            $after = (clone $conversation)->where(fn ($q) => $q->where('sent_at', '>', $at)->orWhere(fn ($q2) => $q2->where('sent_at', $at)->where('id', '>', $message->id)))
                ->orderBy('sent_at')->orderBy('id')->limit(self::CONTEXT)->get();
        }

        return [
            'timezone' => config('app.timezone'),
            'message' => $this->rows([$message])[0],
            'earlier' => $this->rows($before),
            'later' => $this->rows($after),
        ];
    }
}
