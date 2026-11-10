<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Models\Order;
use App\Models\OrderLink;
use App\Models\User;
use App\Support\PublicUrl;
use App\Support\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Telegram alerts about orders, run by `orders:send-reminders` every 10 minutes. Everything is idempotent through
 * the `order_reminders` log (unique per order + kind + user; the claim is inserted BEFORE sending and removed again
 * when the send fails, so the next run retries). Messages are short notices, never cost or profit data. A missing bot
 * token or recipient is not an error: nothing is claimed and nothing is sent.
 *
 *  draft not confirmed   operators of the shop at +reminder_hours[0] and +[1] after creation (only the latest due step
 *                        is sent, never a burst), the admins at +[2]; drafts older than reminder_max_age_hours are ignored.
 *  1C sync failed        every admin, once per order.
 *  not received          operators once the delivery deadline of the order passed, the admins the next morning
 *                        (inventory.admin_reminder_time). Only for orders that reached 1C (synced).
 *  stock-out digest      once a day from inventory.stockout_digest_time (for 3 hours), per operator (his shops)
 *                        and per admin (all shops), built from ForecastService; nothing is sent when nothing is out.
 */
class OrderReminders
{
    private const DIGEST_WINDOW_HOURS = 3;

    private const DIGEST_TOP = 10;

    public function __construct(private readonly OrderTelegram $telegram, private readonly ForecastService $forecast) {}

    /** @return array{sent: int, failed: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $stats = ['sent' => 0, 'failed' => 0];
        if (! $this->telegram->configured()) {
            return $stats;
        }

        foreach ([$this->drafts($now), $this->syncFailed($now), $this->notReceived($now), $this->approvals($now), $this->stockoutDigest($now)] as $part) {
            $stats['sent'] += $part['sent'];
            $stats['failed'] += $part['failed'];
        }

        return $stats;
    }

    /**
     * Manual "Send reminder now" for one order (admin button): no idempotency, goes to the shop's responsible users.
     *
     * @return array{sent: int, failed: int, recipients: int}
     */
    public function sendNow(Order $order): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'recipients' => 0];
        if (! $this->telegram->configured() || ! in_array($order->status, [OrderStatus::Draft, OrderStatus::Confirmed], true)) {
            return $stats;
        }
        $users = OrderTelegram::recipients($order->shop_id);
        $stats['recipients'] = $users->count();
        foreach ($users as $user) {
            $res = $order->status === OrderStatus::Draft ? $this->notifyDraft($order, $user) : $this->notifyReceipt($order, $user);
            $res['ok'] ? $stats['sent']++ : $stats['failed']++;
        }

        return $stats;
    }

    // ---------------------------------------------------------------- drafts

    /** @return array{sent: int, failed: int} */
    private function drafts(Carbon $now): array
    {
        $stats = ['sent' => 0, 'failed' => 0];
        $hours = array_values(array_map('intval', (array) config('inventory.reminder_hours', [2, 4, 6])));
        if (count($hours) < 3) {
            return $stats;
        }
        [$h1, $h2, $hAdmin] = $hours;
        $oldest = $now->copy()->subHours(max((int) config('inventory.reminder_max_age_hours', 48), $hAdmin));

        $drafts = Order::query()->with('shop')
            ->where('status', OrderStatus::Draft->value)
            ->where('created_at', '>=', $oldest->toDateTimeString())
            ->where('created_at', '<=', $now->copy()->subHours($h1)->toDateTimeString())
            ->whereHas('items')
            ->get();

        foreach ($drafts as $order) {
            $age = $order->created_at->diffInMinutes($now) / 60;

            // Operators: the latest due step of the two; not when that step or a later one was sent already.
            $kind = $age >= $h2 ? 'draft_2' : 'draft_1';
            $skip = $kind === 'draft_1' ? ['draft_1', 'draft_2'] : ['draft_2'];
            foreach (OrderTelegram::recipients($order->shop_id) as $user) {
                $this->deliver($stats, $order, $kind, $user, $skip, fn () => $this->notifyDraft($order, $user));
            }

            if ($age >= $hAdmin) {
                foreach (OrderTelegram::admins() as $admin) {
                    $this->deliver($stats, $order, 'draft_escalation', $admin, ['draft_escalation'], fn () => $this->telegram->notify(
                        $order,
                        $admin,
                        implode("\n", [
                            __('Order draft :number for :shop has not been confirmed for :hours hours.', ['number' => $order->number(), 'shop' => $order->shop?->name, 'hours' => (int) floor($age)]),
                            __('The shop managers were reminded.'),
                        ]),
                        __('Open the order'),
                        PublicUrl::to('orders/'.$order->id),
                    ));
                }
            }
        }

        return $stats;
    }

    /** @return array{ok: bool, error: ?string} */
    private function notifyDraft(Order $order, User $user): array
    {
        $order->loadMissing('shop');

        return $this->telegram->notify(
            $order,
            $user,
            implode("\n", [
                __('Reminder: the order draft for :shop is not confirmed yet.', ['shop' => $order->shop?->name]),
                '',
                __('Please open the link to edit or confirm the order.'),
            ]),
            __('Review and confirm'),
            OrderLink::forUser($order, $user)->url(),
        );
    }

    // ----------------------------------------------------------- 1C sync failed

    /** @return array{sent: int, failed: int} */
    private function syncFailed(Carbon $now): array
    {
        $stats = ['sent' => 0, 'failed' => 0];
        $orders = Order::query()->with('shop')
            ->where('status', OrderStatus::Confirmed->value)
            ->where('sync_status', OrderSyncStatus::Failed->value)
            ->where('confirmed_at', '>=', $now->copy()->subHours((int) config('inventory.reminder_max_age_hours', 48))->toDateTimeString())
            ->get();

        foreach ($orders as $order) {
            foreach (OrderTelegram::admins() as $admin) {
                $this->deliver($stats, $order, 'sync_failed', $admin, ['sync_failed'], fn () => $this->telegram->notify(
                    $order,
                    $admin,
                    __('Order :number for :shop could not be sent to 1C. Please check it.', ['number' => $order->number(), 'shop' => $order->shop?->name]),
                    __('Open the order'),
                    PublicUrl::to('orders/'.$order->id),
                ));
            }
        }

        return $stats;
    }

    // ------------------------------------------------------------ not received

    /** @return array{sent: int, failed: int} */
    private function notReceived(Carbon $now): array
    {
        $stats = ['sent' => 0, 'failed' => 0];
        $orders = Order::query()->with('shop')
            ->where('status', OrderStatus::Confirmed->value)
            ->whereNull('received_at')
            ->where('sync_status', OrderSyncStatus::Synced->value)
            ->where(DB::raw('COALESCE(confirmed_at, created_at)'), '>=', $now->copy()->subHours((int) config('inventory.reminder_max_age_hours', 48))->toDateTimeString())
            ->get();

        [$h, $m] = array_pad(array_map('intval', explode(':', (string) config('inventory.admin_reminder_time', '09:00'))), 2, 0);
        foreach ($orders as $order) {
            $deadline = OrderReceiving::deadline($order);
            if ($now->lt($deadline)) {
                continue;
            }

            foreach (OrderTelegram::recipients($order->shop_id) as $user) {
                $this->deliver($stats, $order, 'not_received', $user, ['not_received'], fn () => $this->notifyReceipt($order, $user));
            }

            // The next morning after the delivery day the admins hear about it too.
            if ($now->gte($deadline->copy()->addDay()->startOfDay()->setTime($h, $m))) {
                foreach (OrderTelegram::admins() as $admin) {
                    $this->deliver($stats, $order, 'not_received_admin', $admin, ['not_received_admin'], fn () => $this->telegram->notify(
                        $order,
                        $admin,
                        __('Order :number for :shop was not confirmed as received.', ['number' => $order->number(), 'shop' => $order->shop?->name]),
                        __('Open the order'),
                        PublicUrl::to('orders/'.$order->id),
                    ));
                }
            }
        }

        return $stats;
    }

    /** @return array{ok: bool, error: ?string} */
    private function notifyReceipt(Order $order, User $user): array
    {
        $order->loadMissing('shop');

        return $this->telegram->notify(
            $order,
            $user,
            implode("\n", [
                __('Order :number for :shop has not been confirmed as received.', ['number' => $order->number(), 'shop' => $order->shop?->name]),
                '',
                __('If the goods arrived, please open the link and confirm the receipt.'),
            ]),
            __('Confirm receipt'),
            OrderLink::forUser($order, $user)->url(),
        );
    }

    // ------------------------------------------------------------ approvals

    /** Catch-up for the notice of an order waiting for approval (the immediate send failed): retried every run. */
    private function approvals(Carbon $now): array
    {
        $stats = ['sent' => 0, 'failed' => 0];
        $orders = Order::query()->with('shop')
            ->where('status', OrderStatus::PendingApproval->value)->where('review_status', Order::REVIEW_PENDING)
            ->where('updated_at', '>=', $now->copy()->subHours((int) config('inventory.reminder_max_age_hours', 48))->toDateTimeString())
            ->get();
        foreach ($orders as $order) {
            $this->requestApproval($stats, $order);
        }

        return $stats;
    }

    /** Immediate notice to the admins that an order waits for approval (once per submission). */
    public function approvalRequested(Order $order): void
    {
        if (! $this->telegram->configured()) {
            return;
        }
        $stats = ['sent' => 0, 'failed' => 0];
        $this->requestApproval($stats, $order->loadMissing('shop'));
    }

    /** @param  array{sent: int, failed: int}  $stats */
    private function requestApproval(array &$stats, Order $order): void
    {
        $kind = 'approval_'.$this->submissions($order);
        foreach (OrderTelegram::admins() as $admin) {
            $this->deliver($stats, $order, $kind, $admin, [$kind], fn () => $this->telegram->notify(
                $order,
                $admin,
                __('Order :number for :shop is waiting for your approval (:count exceptions).', ['number' => $order->number(), 'shop' => $order->shop?->name, 'count' => count((array) $order->review_exceptions)]),
                __('Review the order'),
                PublicUrl::to('orders/'.$order->id),
            ));
        }
    }

    /** Tell the shop's responsible users the admin's decision (approved, or sent back with the reason). */
    public function approvalDecided(Order $order, bool $approved): void
    {
        if (! $this->telegram->configured()) {
            return;
        }
        $order->loadMissing('shop');
        $stats = ['sent' => 0, 'failed' => 0];
        $kind = ($approved ? 'approved_' : 'rejected_').$this->submissions($order);
        foreach (OrderTelegram::recipients($order->shop_id) as $user) {
            $this->deliver($stats, $order, $kind, $user, [$kind], fn () => $approved
                ? $this->telegram->notify($order, $user, __('Order :number for :shop was approved and sent.', ['number' => $order->number(), 'shop' => $order->shop?->name]))
                : $this->telegram->notify(
                    $order,
                    $user,
                    implode("\n", [
                        __('Order :number for :shop was sent back: :reason', ['number' => $order->number(), 'shop' => $order->shop?->name, 'reason' => mb_substr((string) $order->review_note, 0, 300)]),
                        __('Please correct it and confirm again.'),
                    ]),
                    __('Review and confirm'),
                    OrderLink::forUser($order, $user)->url(),
                ));
        }
    }

    /** How many times the order was sent for approval (a rejected order can come back: one notice per submission). */
    private function submissions(Order $order): int
    {
        return max(1, (int) DB::table('order_events')->where('order_id', $order->id)->where('action', 'sent_for_approval')->count());
    }

    // ------------------------------------------------------------ stock-out digest

    /** @return array{sent: int, failed: int} */
    private function stockoutDigest(Carbon $now): array
    {
        $stats = ['sent' => 0, 'failed' => 0];
        [$h, $m] = array_pad(array_map('intval', explode(':', (string) config('inventory.stockout_digest_time', '08:30'))), 2, 0);
        $start = $now->copy()->startOfDay()->setTime($h, $m);
        if ($now->lt($start) || $now->gte($start->copy()->addHours(self::DIGEST_WINDOW_HOURS))) {
            return $stats;
        }

        $kind = 'stockout_'.$now->format('Ymd');

        // user id => [User, shop ids (null = every shop)]
        $audience = [];
        foreach (OrderTelegram::admins() as $admin) {
            $audience[$admin->id] = [$admin, null];
        }
        $pairs = DB::table('shop_user')->get(['shop_id', 'user_id']);
        $operators = User::query()->where('role', 'operator')->where('is_active', true)
            ->whereNotNull('telegram_chat_id')->where('telegram_chat_id', '!=', '')
            ->whereIn('id', $pairs->pluck('user_id')->unique())->get(['id', 'name', 'telegram_chat_id']);
        foreach ($operators as $op) {
            $audience[$op->id] = [$op, $pairs->where('user_id', $op->id)->pluck('shop_id')->map(fn ($id) => (int) $id)->all()];
        }

        // Nobody left to serve today = no forecast at all.
        $done = DB::table('order_reminders')->whereNull('order_id')->where('kind', $kind)->pluck('user_id')->all();
        $pending = array_filter($audience, fn ($entry, $userId) => ! in_array($userId, $done, true), ARRAY_FILTER_USE_BOTH);
        if ($pending === []) {
            return $stats;
        }

        $warehouse = Warehouse::shopId();
        $data = $this->forecast->build(null, $warehouse === null ? [] : [$warehouse], $now);
        $shopNames = collect($data['shops'])->pluck('name', 'id');

        // Out of stock, or an A-class item about to run out (cover under a day); rows on stale stock are not reliable.
        $byShop = [];
        foreach ($data['rows'] as $row) {
            if (($row['stale'] ?? false) || ! ($row['level'] === 'out' || ($row['abc'] === 'A' && $row['level'] === 'critical'))) {
                continue;
            }
            $byShop[$row['shop_id']][] = $row;
        }
        if ($byShop === []) {
            foreach ($pending as [$user]) {
                $this->markDone($kind, $user);   // nothing to report: do not rebuild the forecast on every run today
            }

            return $stats;
        }
        foreach ($byShop as $shopId => $rows) {
            usort($rows, fn ($a, $b) => [$a['level'] === 'out' ? 0 : 1, $a['abc'], -($a['speed'] ?? 0)] <=> [$b['level'] === 'out' ? 0 : 1, $b['abc'], -($b['speed'] ?? 0)]);
            $byShop[$shopId] = $rows;
        }

        foreach ($pending as [$user, $shopIds]) {
            $blocks = [];
            foreach ($byShop as $shopId => $rows) {
                if ($shopIds !== null && ! in_array($shopId, $shopIds, true)) {
                    continue;
                }
                $top = array_slice(array_column($rows, 'item'), 0, $shopIds === null ? 3 : self::DIGEST_TOP);
                $blocks[] = __(':shop: :count items', ['shop' => $shopNames[$shopId] ?? '#'.$shopId, 'count' => count($rows)])."\n".implode("\n", array_map(fn ($n) => '- '.$n, $top));
            }
            if ($blocks === []) {
                $this->markDone($kind, $user);

                continue;
            }
            $message = __('Stock-out digest').' '.$now->format('d.m.Y')."\n\n".implode("\n\n", array_slice($blocks, 0, 15));
            $this->deliver($stats, null, $kind, $user, [$kind], fn () => $this->telegram->notify(null, $user, $message));
        }

        return $stats;
    }

    // ------------------------------------------------------------------ idempotency

    /** Records "handled today" for a digest user without sending anything. */
    private function markDone(string $kind, User $user): void
    {
        try {
            DB::table('order_reminders')->insert(['order_id' => null, 'shop_id' => null, 'kind' => $kind, 'user_id' => $user->id, 'sent_at' => now()]);
        } catch (QueryException) {
        }
    }

    /**
     * Claims (order, kind, user) and runs $send; a failed send releases the claim so the next run retries. Nothing is
     * sent when a row with one of $skipKinds exists for the order and user.
     *
     * @param  array{sent: int, failed: int}  $stats
     * @param  array<int, string>  $skipKinds
     * @param  callable(): array{ok: bool, error?: ?string}  $send
     */
    private function deliver(array &$stats, ?Order $order, string $kind, User $user, array $skipKinds, callable $send): void
    {
        $orderId = $order?->id;
        $exists = DB::table('order_reminders')
            ->when($orderId === null, fn ($q) => $q->whereNull('order_id'), fn ($q) => $q->where('order_id', $orderId))
            ->where('user_id', $user->id)->whereIn('kind', $skipKinds)->exists();
        if ($exists) {
            return;
        }

        try {
            $id = DB::table('order_reminders')->insertGetId([
                'order_id' => $orderId,
                'shop_id' => $order?->shop_id,
                'kind' => $kind,
                'user_id' => $user->id,
                'sent_at' => now(),
            ]);
        } catch (QueryException) {
            return;   // another run claimed it
        }

        $res = $send();
        if ($res['ok']) {
            $stats['sent']++;
        } else {
            $stats['failed']++;
            DB::table('order_reminders')->where('id', $id)->delete();
        }
    }
}
