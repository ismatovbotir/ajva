<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderLink;
use App\Models\Telegram as TelegramAccount;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a draft order to the shop's responsible people (operators assigned to the shop with a Telegram chat id on their user) in Telegram: a short summary (no prices or costs) and a
 * "Review and confirm" button that opens the secret link of the draft (/o/{token}). The link works
 * only while the order is a draft. A missing bot token or having no recipient is not an error: nothing is sent and
 * the result says why.
 */
class OrderTelegram
{
    private const TOP_ITEMS = 8;

    public function __construct(private readonly Telegram $telegram) {}

    /** The personal link of one user to the draft (created on first use). */
    public static function link(Order $order, User $user): string
    {
        return OrderLink::forUser($order, $user)->url();
    }

    /**
     * The users who get the order messages of a shop: operators assigned to it (shop_user) with a Telegram chat id.
     *
     * @return Collection<int, User>
     */
    public static function recipients(int $shopId): Collection
    {
        return self::responsible($shopId)->filter(fn (User $u) => trim((string) $u->telegram_chat_id) !== '')->values();
    }

    /**
     * Every ACTIVE operator assigned to the shop (with or without a chat id), for the rules page and the link picker.
     *
     * @return Collection<int, User>
     */
    public static function responsible(int $shopId): Collection
    {
        return User::query()
            ->where('role', UserRole::Operator->value)
            ->where('is_active', true)
            ->whereIn('id', DB::table('shop_user')->where('shop_id', $shopId)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'telegram_chat_id']);
    }

    /**
     * Responsible users of several shops at once.
     *
     * @param  array<int, int>  $shopIds
     * @return array<int, Collection<int, User>> shop id => users (empty collection when none)
     */
    public static function responsibleByShop(array $shopIds): array
    {
        $pairs = DB::table('shop_user')->whereIn('shop_id', $shopIds)->get(['shop_id', 'user_id']);
        $users = User::query()->where('role', UserRole::Operator->value)->where('is_active', true)->whereIn('id', $pairs->pluck('user_id')->unique())->orderBy('name')->get(['id', 'name', 'telegram_chat_id'])->keyBy('id');
        $out = [];
        foreach ($shopIds as $id) {
            $out[$id] = $pairs->where('shop_id', $id)->map(fn ($p) => $users->get($p->user_id))->filter()->sortBy('name')->values();
        }

        return $out;
    }

    /**
     * Sends the draft to the shop's recipients, EACH with his own personal link (one message per user; a failure for
     * one never stops the others) and stores the outcome on the order (telegram_* columns). A full send (null) starts
     * from scratch; a retry ($onlyUserIds = the users that failed) skips everybody who already got the message.
     *
     * @param  array<int, int>|null  $onlyUserIds
     * @return array{status: string, note: string, failed_user_ids: array<int, int>}
     *                                                                               status: sent | no_token | no_recipients | not_draft | failed (at least one recipient failed)
     */
    public function send(Order $order, ?array $onlyUserIds = null): array
    {
        if ($order->status !== OrderStatus::Draft) {
            return $this->result('not_draft', __('Telegram: not sent, the order is no longer a draft.'));
        }
        if (! $this->telegram->configured()) {
            return $this->store($order, $this->result('no_token', __('Telegram: not sent, the bot token (TELEGRAM_BOT_TOKEN) is not set.')), 0);
        }

        $retry = $onlyUserIds !== null;
        $users = self::recipients($order->shop_id);
        if ($retry) {
            $users = $users->filter(fn (User $u) => in_array($u->id, $onlyUserIds, true))->values();
        }
        if ($users->isEmpty() && ! $retry) {
            Log::info('Order draft not sent to Telegram: no responsible user with a chat id', ['order' => $order->id, 'shop' => $order->shop_id]);

            return $this->store($order, $this->result('no_recipients', __('Telegram: not sent, no responsible user with Telegram.')), 0);
        }

        if (! $retry) {
            OrderLink::query()->where('order_id', $order->id)->update(['telegram_sent_at' => null]);
        }

        $message = $this->message($order);
        $button = __('Review and confirm');

        $sent = 0;
        $failed = [];
        $errors = [];
        foreach ($users as $user) {
            $link = OrderLink::forUser($order, $user);
            if ($retry && $link->telegram_sent_at !== null) {
                continue;   // already delivered, never twice
            }
            try {
                $res = $this->telegram->send(trim((string) $user->telegram_chat_id), $message, $button, $link->url());
            } catch (Throwable $e) {
                $res = ['ok' => false, 'error' => class_basename($e)];
            }
            $this->log($order, $user, $res, $message);
            if ($res['ok']) {
                $sent++;
                $link->forceFill(['telegram_sent_at' => now()])->save();
            } else {
                $failed[] = $user;
                $errors[$res['error']] = true;
            }
        }

        if ($failed === []) {
            return $this->store($order, $this->result('sent', __('Telegram: link sent (:count recipients).', ['count' => $sent])), $sent);
        }

        return $this->store($order, $this->result('failed', __('Telegram: sent to :sent, failed for :names (:error).', [
            'sent' => $sent,
            'names' => collect($failed)->pluck('name')->implode(', '),
            'error' => implode('; ', array_keys($errors)),
        ]), collect($failed)->pluck('id')->map(fn ($id) => (int) $id)->all()), $sent);
    }

    /** One delivery row per attempt (the Bot API only says accepted or not; it has no delivered / read status). */
    private function log(Order $order, User $user, array $res, string $message): void
    {
        $chatId = trim((string) $user->telegram_chat_id);

        try {
            TelegramMessage::query()->create([
                'telegram_id' => TelegramAccount::query()->where('tg_id', $chatId)->value('id'),
                'direction' => 'out',
                'chat_id' => (int) $chatId,
                'chat_type' => 'private',
                'message_id' => $res['message_id'] ?? null,
                'type' => 'text',
                'status' => $res['ok'] ? 'sent' : 'failed',
                'error' => $res['ok'] ? null : mb_substr((string) $res['error'], 0, 250),
                'text' => mb_substr($message, 0, 4000),
                'order_id' => $order->id,
                'user_id' => $user->id,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);   // a logging problem never fails the send
        }
    }

    /** Records the outcome on the order: status, note, how many current recipients have the message, time of the last delivery. */
    public function store(Order $order, array $result, int $deliveredNow): array
    {
        $ok = OrderLink::query()
            ->where('order_id', $order->id)
            ->whereNotNull('telegram_sent_at')
            ->whereIn('user_id', self::recipients($order->shop_id)->pluck('id'))
            ->count();

        $order->forceFill(array_filter([
            'telegram_status' => $result['status'],
            'telegram_note' => mb_substr($result['note'], 0, 250),
            'telegram_recipients_ok' => $ok,
            'telegram_sent_at' => $deliveredNow > 0 ? now() : null,
        ], fn ($v, $k) => $k !== 'telegram_sent_at' || $v !== null, ARRAY_FILTER_USE_BOTH))->save();

        return $result;
    }

    /** @param  array<int, int>  $failed  @return array{status: string, note: string, failed_user_ids: array<int, int>} */
    private function result(string $status, string $note, array $failed = []): array
    {
        return ['status' => $status, 'note' => $note, 'failed_user_ids' => $failed];
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function sendTest(string $chatId, string $label): array
    {
        return $this->telegram->send($chatId, __('Test message from :app for :label. Order drafts will arrive in this chat.', ['app' => config('app.name'), 'label' => $label]));
    }

    public function configured(): bool
    {
        return $this->telegram->configured();
    }

    public function message(Order $order): string
    {
        $order->loadMissing('shop');
        $items = $order->items()->with('item')->get();
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
        $flagsOf = fn ($line) => array_filter(explode(',', (string) $line->flags));

        $sorted = $items->sortBy(fn ($l) => [
            in_array('out', $flagsOf($l), true) ? 0 : 1,
            $l->cover_days === null ? PHP_INT_MAX : (float) $l->cover_days,
            -(float) $l->qty,
        ])->values();

        $out = [];
        $out[] = __('Order draft for :shop', ['shop' => $order->shop?->name]);
        $out[] = __('Date: :date', ['date' => now()->format('d.m.Y')]);
        $out[] = __(':lines lines, total quantity :qty', ['lines' => $items->count(), 'qty' => $fmt($items->sum('qty'))]);
        $out[] = '';
        $out[] = __('Most important items:');
        foreach ($sorted->take(self::TOP_ITEMS) as $i => $line) {
            $markers = [];
            $flags = $flagsOf($line);
            in_array('out', $flags, true) && $markers[] = __('out of stock');
            in_array('short', $flags, true) && $markers[] = __('warehouse is short');
            in_array('understated', $flags, true) && $markers[] = __('demand understated');
            $out[] = __(':n. :name: :qty (stock :est, cover :cover)', [
                'n' => $i + 1,
                'name' => $line->item?->name,
                'qty' => $fmt($line->qty),
                'est' => $line->est === null ? '-' : $fmt($line->est),
                'cover' => $line->cover_days === null ? '-' : __(':days d', ['days' => $fmt($line->cover_days)]),
            ]).($markers === [] ? '' : ' ['.implode(', ', $markers).']');
        }
        if ($sorted->count() > self::TOP_ITEMS) {
            $out[] = __('+ :count more lines', ['count' => $sorted->count() - self::TOP_ITEMS]);
        }

        $short = $items->filter(fn ($l) => in_array('short', $flagsOf($l), true))->count();
        if ($short > 0) {
            $out[] = '';
            $out[] = __('The warehouse cannot cover :count items in full.', ['count' => $short]);
        }

        $warnings = $this->warnings($order, $items->filter(fn ($l) => in_array('low', $flagsOf($l), true))->count());
        if ($warnings !== []) {
            $out[] = '';
            $out[] = __('Warnings:');
            foreach ($warnings as $w) {
                $out[] = '- '.$w;
            }
        }

        $out[] = '';
        $out[] = __('Review the order and confirm it with the button below. The link stops working once the order is confirmed.');

        return implode("\n", $out);
    }

    /** @return array<int, string> */
    private function warnings(Order $order, int $low): array
    {
        $kept = [];
        foreach (array_filter(explode(',', (string) $order->warnings)) as $pair) {
            [$key, $count] = array_pad(explode('=', $pair, 2), 2, '0');
            $kept[$key] = (int) $count;
        }

        $out = [];
        ($kept['stale'] ?? 0) > 0 && $out[] = __(':count items left out: their stock data is out of date.', ['count' => $kept['stale']]);
        ($kept['none'] ?? 0) > 0 && $out[] = __(':count items left out: not enough sales history.', ['count' => $kept['none']]);
        ($kept['capped'] ?? 0) > 0 && $out[] = __(':count quantities were capped at 3 x the maximum.', ['count' => $kept['capped']]);
        ($kept['truncated'] ?? 0) > 0 && $out[] = __(':count more lines were left out (limit :limit lines).', ['count' => $kept['truncated'], 'limit' => ReplenishmentOrders::AUTO_MAX_LINES]);
        $low > 0 && $out[] = __(':count items are based on only a few days of sales.', ['count' => $low]);

        return $out;
    }
}
