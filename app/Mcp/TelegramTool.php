<?php

namespace App\Mcp;

use App\Models\Telegram;
use App\Models\TelegramMessage;
use App\Models\User;

/**
 * Shared privacy rules of the Telegram tools (not listed in config/mcp.php): rows never carry chat ids, Telegram ids,
 * from_id, phone numbers or payloads; a person is shown by name / @username / internal id only; text is scrubbed and
 * truncated.
 */
abstract class TelegramTool extends BaseTool
{
    protected const TEXT_MAX = 500;

    /** SQL: the person (telegrams row) is linked to a CRM user. Same link the Telegram > Users page sets. */
    protected function linkedExists(string $telegramsTable = 'telegrams'): string
    {
        return "EXISTS (SELECT 1 FROM users WHERE users.telegram_chat_id = CAST({$telegramsTable}.tg_id AS CHAR))";
    }

    /** The app-timezone moment as it is stored. */
    protected function sqlTime($value): string
    {
        return $value->format('Y-m-d H:i:s');
    }

    /**
     * @param  iterable<TelegramMessage>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function rows(iterable $messages): array
    {
        $messages = collect($messages);
        $people = Telegram::query()->whereIn('id', $messages->pluck('telegram_id')->filter()->unique())->get()->keyBy('id');
        $users = User::query()
            ->whereIn('telegram_chat_id', $people->pluck('tg_id')->map(fn ($id) => (string) $id)->all())
            ->get(['name', 'role', 'telegram_chat_id'])->keyBy('telegram_chat_id');

        return $messages->map(function (TelegramMessage $m) use ($people, $users) {
            $person = $m->telegram_id ? $people->get($m->telegram_id) : null;
            $user = $person ? $users->get((string) $person->tg_id) : null;
            $text = (string) $m->text;
            $row = [
                'id' => $m->id,
                'direction' => $m->direction,
                'at' => $this->stamp($m->sent_at ?? $m->created_at),
                'person' => $person === null ? null : [
                    'id' => $person->id,
                    'name' => $this->personName($person),
                    'username' => $person->username,
                    'linked_user' => $user === null ? null : ['name' => $user->name, 'role' => $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role],
                ],
                'type' => $m->type,
                'text' => $this->scrub($text, self::TEXT_MAX),
                'text_truncated' => mb_strlen($text) > self::TEXT_MAX,
            ];
            if ($m->direction === 'out') {
                $row['order'] = $m->order_id ? 'ORD-'.str_pad((string) $m->order_id, 6, '0', STR_PAD_LEFT) : null;
                $row['status'] = $m->status;
                $row['error'] = $this->scrub($m->error, 120);
            }

            return $row;
        })->values()->all();
    }

    protected function personName(Telegram $person): string
    {
        $name = trim($person->first_name.' '.$person->last_name);

        return $name !== '' ? $name : ($person->username ? '@'.$person->username : 'Person '.$person->id);
    }

    /** SQL escape for LIKE using "!" as the escape character. */
    protected function likeEscape(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }
}
