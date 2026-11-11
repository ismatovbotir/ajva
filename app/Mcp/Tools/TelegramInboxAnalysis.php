<?php

namespace App\Mcp\Tools;

use App\Mcp\TelegramTool;
use App\Models\TelegramMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TelegramInboxAnalysis extends TelegramTool
{
    private const SAMPLE = 5000;

    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function name(): string
    {
        return 'telegram_inbox_analysis';
    }

    public function description(): string
    {
        return 'Analysis of the Telegram bot inbox (mostly RECEIVED messages) for a period (default last 30 days, max 92): totals in / out; received per day, per hour of day and per weekday (app timezone); message types; top senders; new vs returning senders; how many senders are linked to CRM users and which unlinked people write to the bot; response metrics (received messages with no reply, median / p90 time to the first reply, oldest unanswered age; automatic order notifications do not count as a reply); text analytics (average length, share of commands, top keywords and repeated messages as counts); delivery health of our outgoing order messages (sent / failed per day, top errors). Keyword and repeat figures use the newest '.self::SAMPLE.' received text messages of the period (sampled flag). Read the notes array for what is not measured. No chat ids, Telegram ids or full texts.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 92, 'description' => 'Period length ending now (default 30).'],
            ],
        ];
    }

    public function handle(array $arguments): array
    {
        $days = $this->optionalInt($arguments, 'days', 1, 92) ?? 30;
        $now = now();
        $from = $now->copy()->subDays($days - 1)->startOfDay();
        $fromSql = $this->sqlTime($from);
        $toSql = $this->sqlTime($now);

        $in = fn () => TelegramMessage::query()->where('direction', 'in')->whereBetween('sent_at', [$fromSql, $toSql]);

        $byDirection = TelegramMessage::query()->whereBetween('sent_at', [$fromSql, $toSql])
            ->selectRaw('direction, COUNT(*) AS c')->groupBy('direction')->pluck('c', 'direction');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $now->toDateString(), 'days' => $days, 'timezone' => config('app.timezone')],
            'totals' => ['received' => (int) ($byDirection['in'] ?? 0), 'sent' => (int) ($byDirection['out'] ?? 0)],
            'received_per_day' => $this->perDay($in(), $from, $days),
            'received_per_hour' => $this->perHour($in()),
            'received_per_weekday' => $this->perWeekday($in()),
            'types' => $in()->selectRaw('type, COUNT(*) AS c')->groupBy('type')->orderByDesc('c')->limit(15)->get()
                ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->c])->all(),
            'senders' => $this->senders($from, $fromSql, $toSql),
            'response' => $this->response($fromSql, $toSql, $now),
            'text' => $this->textAnalytics($fromSql, $toSql),
            'order_delivery' => $this->delivery($fromSql, $toSql),
            'notes' => [
                'Times are in the app timezone ('.config('app.timezone').'); hour and weekday buckets use it too.',
                'The Telegram Bot API has no delivered / read status: "sent" only means Telegram accepted the message, and nothing shows whether a person read it.',
                'A reply is a later outgoing message to the same person that is not an automatic order notification; a conversation answered outside the bot (phone, other chat) still counts as unanswered.',
                'Keywords are naive lowercase word counts (words of 3+ letters, minimal stop-word list in config/mcp.php, no stemming, no language detection); messages starting with "/" are commands and are left out of keywords.',
                'Keyword and repeated-message figures use at most the newest '.self::SAMPLE.' received text messages of the period; reply times use at most the newest '.self::SAMPLE.' answered messages.',
                'Messages without a known sender record are counted in totals but not in sender figures.',
            ],
        ];
    }

    /** @return array<int, array{date: string, count: int}> */
    private function perDay($query, Carbon $from, int $days): array
    {
        $counts = $query->selectRaw('DATE(sent_at) AS bucket, COUNT(*) AS c')->groupBy('bucket')->pluck('c', 'bucket');
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return $out;
    }

    /** @return array<int, array{hour: int, count: int}> */
    private function perHour($query): array
    {
        $counts = $query->selectRaw($this->hourExpression('sent_at').' AS bucket, COUNT(*) AS c')->groupBy('bucket')->pluck('c', 'bucket');

        return array_map(fn ($h) => ['hour' => $h, 'count' => (int) ($counts[$h] ?? 0)], range(0, 23));
    }

    /** @return array<int, array{weekday: string, count: int}> Monday first. */
    private function perWeekday($query): array
    {
        $expression = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%w', sent_at) AS INTEGER)"
            : '(DAYOFWEEK(sent_at) - 1)';
        $counts = $query->selectRaw("{$expression} AS bucket, COUNT(*) AS c")->groupBy('bucket')->pluck('c', 'bucket');

        return array_map(fn ($w) => ['weekday' => self::WEEKDAYS[$w], 'count' => (int) ($counts[$w] ?? 0)], [1, 2, 3, 4, 5, 6, 0]);
    }

    /** @return array<string, mixed> */
    private function senders(Carbon $from, string $fromSql, string $toSql): array
    {
        $rows = DB::table('telegram_messages')
            ->join('telegrams', 'telegrams.id', '=', 'telegram_messages.telegram_id')
            ->where('telegram_messages.direction', 'in')
            ->whereBetween('telegram_messages.sent_at', [$fromSql, $toSql])
            ->groupBy('telegrams.id', 'telegrams.username', 'telegrams.first_name', 'telegrams.last_name', 'telegrams.tg_id')
            ->selectRaw('telegrams.id AS pid, telegrams.username, telegrams.first_name, telegrams.last_name, COUNT(*) AS cnt, MAX(telegram_messages.sent_at) AS last_at, '.$this->linkedExists().' AS is_linked')
            ->orderByDesc('cnt')->limit(5000)->get();

        // First message ever of each of these people: new = the first one falls inside the period.
        $firstEver = DB::table('telegram_messages')->where('direction', 'in')->whereIn('telegram_id', $rows->pluck('pid'))
            ->selectRaw('telegram_id, MIN(sent_at) AS first_at')->groupBy('telegram_id')->pluck('first_at', 'telegram_id');

        $shape = function ($r) use ($firstEver, $fromSql) {
            $name = trim($r->first_name.' '.$r->last_name);

            return [
                'person_id' => (int) $r->pid,
                'name' => $name !== '' ? $name : ($r->username ? '@'.$r->username : 'Person '.$r->pid),
                'username' => $r->username,
                'messages' => (int) $r->cnt,
                'last_message_at' => $this->stamp($r->last_at),
                'new_in_period' => ($firstEver[$r->pid] ?? '') >= $fromSql,
                'linked_to_crm_user' => (bool) $r->is_linked,
            ];
        };

        $linked = $rows->filter(fn ($r) => (bool) $r->is_linked);
        $unlinked = $rows->reject(fn ($r) => (bool) $r->is_linked);
        $new = $rows->filter(fn ($r) => ($firstEver[$r->pid] ?? '') >= $fromSql)->count();
        $total = $rows->count();

        return [
            'distinct' => $total,
            'new' => $new,
            'returning' => $total - $new,
            'linked_to_crm' => $linked->count(),
            'unlinked' => $unlinked->count(),
            'unlinked_share_pct' => $total > 0 ? round($unlinked->count() * 100 / $total, 1) : null,
            'unlinked_messages' => (int) $unlinked->sum('cnt'),
            'top' => $rows->take(10)->map($shape)->values()->all(),
            'top_unlinked' => $unlinked->take(10)->map($shape)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function response(string $fromSql, string $toSql, Carbon $now): array
    {
        // "Replied" = a later outgoing message to the same person that is not an automatic order notification.
        $replied = function ($sub) {
            $sub->selectRaw('1')->from('telegram_messages as r')
                ->whereColumn('r.telegram_id', 'telegram_messages.telegram_id')
                ->where('r.direction', 'out')->whereNull('r.order_id')
                ->whereColumn('r.sent_at', '>', 'telegram_messages.sent_at');
        };
        $received = fn () => TelegramMessage::query()->where('direction', 'in')->whereBetween('sent_at', [$fromSql, $toSql]);

        $unanswered = $received()->whereNotExists($replied);
        $count = (clone $unanswered)->count();
        $oldest = (clone $unanswered)->min('sent_at');

        $firstReply = DB::table('telegram_messages as r')->selectRaw('MIN(r.sent_at)')
            ->whereColumn('r.telegram_id', 'telegram_messages.telegram_id')
            ->where('r.direction', 'out')->whereNull('r.order_id')
            ->whereColumn('r.sent_at', '>', 'telegram_messages.sent_at');

        $pairs = $received()->whereExists($replied)
            ->select('sent_at')->selectSub($firstReply, 'replied_at')
            ->orderByDesc('sent_at')->limit(self::SAMPLE)->get();

        $seconds = $pairs->map(fn ($p) => max(0, Carbon::parse($p->replied_at)->getTimestamp() - Carbon::parse($p->sent_at)->getTimestamp()))->sort()->values();
        $pick = fn (float $q) => $seconds->isEmpty() ? null : (int) $seconds[(int) max(0, ceil($q * $seconds->count()) - 1)];

        return [
            'unanswered' => $count,
            'oldest_unanswered_at' => $this->stamp($oldest),
            'oldest_unanswered_age_hours' => $oldest === null ? null : round(Carbon::parse($oldest)->diffInMinutes($now) / 60, 1),
            'answered_measured' => $seconds->count(),
            'median_first_reply_minutes' => $pick(0.5) === null ? null : round($pick(0.5) / 60, 1),
            'p90_first_reply_minutes' => $pick(0.9) === null ? null : round($pick(0.9) / 60, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function textAnalytics(string $fromSql, string $toSql): array
    {
        $texts = fn () => TelegramMessage::query()->where('direction', 'in')->where('type', 'text')->whereNotNull('text')->whereBetween('sent_at', [$fromSql, $toSql]);

        $total = $texts()->count();
        $commands = $texts()->whereRaw("text LIKE '/%'")->count();
        $sample = $texts()->orderByDesc('id')->limit(self::SAMPLE)->pluck('text');

        $stop = array_flip(array_map('mb_strtolower', (array) config('mcp.telegram_stop_words', [])));
        $words = [];
        $repeats = [];
        $length = 0;
        foreach ($sample as $text) {
            $text = (string) $text;
            $length += mb_strlen($text);
            if (str_starts_with(ltrim($text), '/')) {
                continue;
            }
            $normal = trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)));
            if ($normal !== '') {
                $repeats[$normal] = ($repeats[$normal] ?? 0) + 1;
            }
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $normal, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (mb_strlen($word) < 3 || preg_match('/\d/', $word) || isset($stop[$word])) {
                    continue;
                }
                $words[$word] = ($words[$word] ?? 0) + 1;
            }
        }
        arsort($words);
        arsort($repeats);

        return [
            'text_messages' => $total,
            'sampled' => $total > self::SAMPLE,
            'sample_size' => $sample->count(),
            'avg_length_chars' => $sample->isEmpty() ? null : round($length / $sample->count(), 1),
            'command_messages' => $commands,
            'command_share_pct' => $total > 0 ? round($commands * 100 / $total, 1) : null,
            'top_keywords' => collect($words)->take(20)->map(fn ($c, $w) => ['word' => $w, 'count' => $c])->values()->all(),
            'top_repeated_messages' => collect($repeats)->filter(fn ($c) => $c >= 3)->take(10)
                ->map(fn ($c, $t) => ['text' => $this->scrub($t, 80), 'count' => $c])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function delivery(string $fromSql, string $toSql): array
    {
        $orderOut = fn () => TelegramMessage::query()->where('direction', 'out')->whereNotNull('order_id')->whereBetween('sent_at', [$fromSql, $toSql]);

        $perDay = $orderOut()->selectRaw('DATE(sent_at) AS bucket, status, COUNT(*) AS c')->groupBy('bucket', 'status')->orderBy('bucket')->get();
        $days = [];
        foreach ($perDay as $r) {
            $days[$r->bucket][$r->status === 'failed' ? 'failed' : 'sent'] = ($days[$r->bucket][$r->status === 'failed' ? 'failed' : 'sent'] ?? 0) + (int) $r->c;
        }

        $errors = [];
        foreach ($orderOut()->where('status', 'failed')->selectRaw('error, COUNT(*) AS c')->groupBy('error')->orderByDesc('c')->limit(50)->get() as $r) {
            $reason = $this->scrub($r->error, 120) ?? 'unknown';
            $errors[$reason] = ($errors[$reason] ?? 0) + (int) $r->c;
        }
        arsort($errors);

        return [
            'sent' => (int) $orderOut()->where('status', '!=', 'failed')->count(),
            'failed' => (int) $orderOut()->where('status', 'failed')->count(),
            'per_day' => collect($days)->map(fn ($v, $d) => ['date' => $d, 'sent' => $v['sent'] ?? 0, 'failed' => $v['failed'] ?? 0])->values()->all(),
            'top_errors' => collect($errors)->take(10)->map(fn ($c, $e) => ['error' => $e, 'count' => $c])->values()->all(),
        ];
    }
}
