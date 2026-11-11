<?php

namespace App\Mcp\Tools;

use App\Mcp\InvalidArguments;
use App\Mcp\TelegramTool;
use App\Models\TelegramMessage;

class ListTelegramMessages extends TelegramTool
{
    public function name(): string
    {
        return 'list_telegram_messages';
    }

    public function description(): string
    {
        return 'Telegram bot messages, newest first. By default only RECEIVED messages (direction in, written to the bot by people); direction out = what the bot / panel sent (including order notifications with their order number and sent / failed status), both = the two together. Filters: date range, person id, message type (text, photo, contact, ...), text search, only_unanswered (received messages with no later reply to the same person; automatic order notifications do not count as a reply) and linked_user (true = the person is linked to a CRM user, false = unknown to the CRM). Rows hold the person (name, @username, linked CRM user name and role), the app-timezone time, type and the text cut to '.self::TEXT_MAX.' characters (long digit runs are masked). Chat ids, Telegram ids and phone numbers are never returned. Page with next_cursor -> before_id. For counts, hours, response times and keywords use telegram_inbox_analysis.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'direction' => ['type' => 'string', 'enum' => ['in', 'out', 'both'], 'description' => 'in = received (default), out = sent by the bot, both.'],
                'from' => ['type' => 'string', 'description' => 'On or after this day (YYYY-MM-DD).', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'to' => ['type' => 'string', 'description' => 'On or before this day (YYYY-MM-DD).', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'person_id' => ['type' => 'integer', 'description' => 'Internal person id (person.id of a row), not a Telegram id.'],
                'type' => ['type' => 'string', 'description' => 'Message type: text, photo, document, voice, video, audio, sticker, location, contact, other.'],
                'search' => ['type' => 'string', 'description' => 'Text contains this (case-insensitive, max 100 characters).'],
                'only_unanswered' => ['type' => 'boolean', 'description' => 'Only received messages that have no later reply to the same person.'],
                'linked_user' => ['type' => 'boolean', 'description' => 'true = only people linked to a CRM user, false = only people not linked.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Max rows (default 50).'],
                'before_id' => ['type' => 'integer', 'description' => 'Cursor: only messages with an id below this (use next_cursor of the previous page).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $direction = $this->optionalEnum($arguments, 'direction', ['in', 'out', 'both']) ?? 'in';
        $limit = $this->optionalInt($arguments, 'limit', 1, 200) ?? 50;
        $personId = $this->optionalInt($arguments, 'person_id', 1);
        $before = $this->optionalInt($arguments, 'before_id', 1);
        $from = isset($arguments['from']) ? $this->dayRange(['date' => $arguments['from']])[0] : null;
        $to = isset($arguments['to']) ? $this->dayRange(['date' => $arguments['to']])[1] : null;
        $type = isset($arguments['type']) ? strtolower(trim((string) $arguments['type'])) : null;
        $search = isset($arguments['search']) ? trim((string) $arguments['search']) : null;
        if ($search !== null && mb_strlen($search) > 100) {
            throw new InvalidArguments('search must be at most 100 characters.');
        }
        foreach (['only_unanswered', 'linked_user'] as $flag) {
            if (isset($arguments[$flag]) && ! is_bool($arguments[$flag])) {
                throw new InvalidArguments("{$flag} must be true or false.");
            }
        }
        $unanswered = (bool) ($arguments['only_unanswered'] ?? false);
        $linked = $arguments['linked_user'] ?? null;
        if ($unanswered) {
            $direction = 'in';
        }

        $query = TelegramMessage::query()
            ->when($direction !== 'both', fn ($q) => $q->where('direction', $direction))
            ->when($personId, fn ($q) => $q->where('telegram_id', $personId))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($from, fn ($q) => $q->where('sent_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sent_at', '<=', $to))
            ->when($search !== null && $search !== '', fn ($q) => $q->whereRaw("text LIKE ? ESCAPE '!'", ['%'.$this->likeEscape($search).'%']))
            ->when($unanswered, fn ($q) => $q->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('telegram_messages as r')
                    ->whereColumn('r.telegram_id', 'telegram_messages.telegram_id')
                    ->where('r.direction', 'out')->whereNull('r.order_id')
                    ->whereColumn('r.sent_at', '>', 'telegram_messages.sent_at');
            }))
            ->when($linked !== null, fn ($q) => $q->whereIn('telegram_id', function ($sub) use ($linked) {
                $sub->select('telegrams.id')->from('telegrams')->whereRaw(($linked ? '' : 'NOT ').$this->linkedExists());
            }));

        $matching = (clone $query)->count();

        $page = $query
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $more = $page->count() > $limit;
        $page = $page->take($limit);

        return [
            'matching' => $matching,
            'returned' => $page->count(),
            'next_cursor' => $more ? $page->last()->id : null,
            'timezone' => config('app.timezone'),
            'messages' => $this->rows($page),
        ];
    }
}
