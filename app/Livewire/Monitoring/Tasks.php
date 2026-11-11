<?php

namespace App\Livewire\Monitoring;

use App\Enums\OrderSyncStatus;
use App\Models\Order;
use App\Models\OrderRule;
use App\Support\TaskRuns;
use App\Support\TelegramStatus;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Monitoring > Tasks: live board of every scheduled command, the scheduler heartbeat, the queue (pending and
 * failed jobs, retry / forget) and the 1C order sync, with one overall status on top. Admin only.
 */
#[Layout('components.layouts.app', ['title' => 'Tasks'])]
class Tasks extends Component
{
    /** Runs on every request, including Livewire updates (they skip route middleware). */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /** Result of the last Retry / Forget press. */
    public string $output = '';

    /** Re-queues one failed job. */
    public function retry(string $uuid): void
    {
        abort_unless($this->failedExists($uuid), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->output = __('The job was put back in the queue.');
    }

    /** Deletes one failed job. */
    public function forget(string $uuid): void
    {
        abort_unless($this->failedExists($uuid), 404);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->output = __('The failed job was removed.');
    }

    private function failedExists(string $uuid): bool
    {
        return Schema::hasTable('failed_jobs') && DB::table('failed_jobs')->where('uuid', $uuid)->exists();
    }

    /** Known commands and what they do (the artisan description is the fallback for any other scheduled command). */
    private function catalog(): array
    {
        return [
            'orders:auto-generate' => __('Creates the daily order drafts and sends them to managers'),
            'telegram:poll' => __('Fetches new Telegram bot messages'),
            'orders:send-telegram' => __('Sends order links to managers (on demand)'),
            'orders:send-reminders' => __('Reminds managers about orders that are not accepted yet'),
            'queue:work' => __('Queue worker: runs the queued jobs (1C sync, Telegram)'),
            'orders:expire-transit' => __('Closes orders that stay in transit too long'),
            'security:digest' => __('Tells the admins how many high security warnings the receipts raised'),
        ];
    }

    /** @return array<int, array<string, mixed>> one row per task */
    private function tasks(): array
    {
        $commands = Artisan::all(); // also boots the console kernel, which defines the schedule
        $events = [];
        foreach (app(Schedule::class)->events() as $event) {
            if (($name = TaskRuns::commandName($event)) !== null) {
                $events[$name] = $event;
            }
        }

        $names = collect(array_keys($this->catalog()))
            ->filter(fn ($n) => isset($commands[$n]))
            ->merge(array_keys($events))
            ->unique()->values();

        $auto = OrderRule::query()->whereNotNull('last_run_date')->orderByDesc('last_run_date')->first(['last_run_date', 'last_run_status', 'last_run_note']);

        return $names->map(function (string $name) use ($events, $commands, $auto) {
            $event = $events[$name] ?? null;
            $run = TaskRuns::last($name);
            $at = $run ? Carbon::parse($run->finished_at) : null;
            $state = $run ? ($run->status === 'ok' ? 'ok' : 'error') : 'never';
            $note = $run->note ?? null;

            // Commands that report their own outcome (exit code 0 but a stored error).
            if ($name === 'telegram:poll' && ($tg = TelegramStatus::get('poll')) && in_array($tg['status'] ?? null, ['error', 'no_token'], true)) {
                $state = 'error';
                $note = ($tg['status'] === 'no_token') ? __('The Telegram bot token (TELEGRAM_BOT_TOKEN) is not set.') : (string) ($tg['error'] ?? 'error');
                $at = $at ?? $tg['at'];
            }
            if ($name === 'orders:send-telegram' && ($tg = TelegramStatus::get('send'))) {
                $at = $tg['at'];
                $state = ($tg['failed'] ?? false) ? 'error' : 'ok';
                $note = __('Orders').': '.($tg['orders'] ?? 0).' · '.__('Sent').': '.($tg['sent'] ?? 0);
            }
            if ($name === 'orders:auto-generate' && $auto && $auto->last_run_status === 'error' && $state !== 'error') {
                $state = 'error';
                $note = (string) $auto->last_run_note;
            }

            if ($event && $at !== null && $state !== 'never' && $this->overdue($event, $at)) {
                $state = 'overdue';
            }

            return [
                'name' => $name,
                'what' => $this->catalog()[$name] ?? (isset($commands[$name]) ? $commands[$name]->getDescription() : ''),
                'schedule' => $event ? $this->scheduleLabel($event) : __('On demand'),
                'at' => $at,
                'state' => $state,
                'note' => $note,
                'duration' => $run->duration_ms ?? null,
                'next' => $event ? Carbon::instance($event->nextRunDate()) : null,
            ];
        })->all();
    }

    private function scheduleLabel(Event $event): string
    {
        $labels = [
            '* * * * *' => __('every minute'),
            '*/5 * * * *' => __('every 5 minutes'),
            '*/10 * * * *' => __('every 10 minutes'),
            '*/15 * * * *' => __('every 15 minutes'),
            '0 * * * *' => __('every hour'),
            '0 0 * * *' => __('daily at 00:00'),
        ];

        return $labels[$event->expression] ?? $event->expression;
    }

    /** True when a run that should have happened is missing: the last run is older than the previous due time minus a grace. */
    private function overdue(Event $event, Carbon $lastRun): bool
    {
        $cron = new CronExpression($event->expression);
        $previous = Carbon::instance($cron->getPreviousRunDate(now(), 0, true));
        $following = Carbon::instance($cron->getNextRunDate($previous));
        $graceMinutes = max(3, (int) round($previous->diffInMinutes($following) / 2));

        return $lastRun->lt($previous->copy()->subMinutes($graceMinutes));
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $driver = (string) config('queue.default');
        $hasJobs = Schema::hasTable('jobs');
        $pending = $hasJobs ? DB::table('jobs')->count() : null;
        $oldest = null;
        if ($driver === 'database' && $hasJobs) {
            $min = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', time())->min('created_at');
            $oldest = $min !== null ? max(0, time() - (int) $min) : null;
        }

        $failedTotal = null;
        $failed = collect();
        if (Schema::hasTable('failed_jobs')) {
            $failedTotal = DB::table('failed_jobs')->count();
            $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(10)->get()->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'uuid' => $row->uuid,
                    'job' => class_basename((string) ($payload['displayName'] ?? $payload['job'] ?? __('Unknown job'))),
                    'queue' => $row->queue,
                    'at' => Carbon::parse($row->failed_at),
                    'error' => Str::limit(trim((string) strtok((string) $row->exception, "\n")), 240),
                ];
            });
        }

        // Waiting-jobs counts of the last 6 heartbeats (oldest first): growing every time = the worker does not keep up.
        $samples = DB::table('task_runs')->where('name', TaskRuns::HEARTBEAT)->orderByDesc('id')->limit(6)->pluck('note')
            ->map(fn ($n) => preg_match('/pending=(\d+)/', (string) $n, $m) === 1 ? (int) $m[1] : null)->reverse()->values()->all();
        $growing = count($samples) === 6 && ! in_array(null, $samples, true) && $samples[5] > $samples[0] && $samples[5] >= 5;
        for ($i = 1; $growing && $i < 6; $i++) {
            $growing = $samples[$i] >= $samples[$i - 1];
        }

        return [
            'growing' => $growing,
            'driver' => $driver,
            'pending' => $pending,
            'oldest' => $oldest,
            'workerDead' => $oldest !== null && $oldest > 120,
            'failedTotal' => $failedTotal,
            'failed' => $failed,
        ];
    }

    /** @return array<string, mixed> */
    private function sync(): array
    {
        $count = fn (OrderSyncStatus $s) => Order::query()->where('sync_status', $s->value)->count();
        $lastFailed = Order::query()->where('sync_status', OrderSyncStatus::Failed->value)->whereNotNull('sync_error')->latest('id')->first();

        return [
            'queued' => $count(OrderSyncStatus::Queued),
            'failed' => $count(OrderSyncStatus::Failed),
            'syncedToday' => Order::query()->where('sync_status', OrderSyncStatus::Synced->value)->where('synced_at', '>=', now()->startOfDay())->count(),
            'lastError' => $lastFailed ? $lastFailed->number().': '.Str::limit((string) $lastFailed->sync_error, 240) : null,
            'urlMissing' => trim((string) config('services.onec.orders_url')) === '',
        ];
    }

    /**
     * Last data push from 1C: items are upserted (updated_at) on every items sync, stocks only carry the sync DAY.
     *
     * @return array{errors_today: int, at: ?Carbon, ago: ?string, stock_date: ?string, stale: bool}
     */
    private function oneC(): array
    {
        // The items sync log (one row per day) holds the real update time; items.updated_at is the fallback until a
        // first batch has been logged.
        $at = DB::table('item_sync_days')->max('last_success_at') ?: DB::table('items')->max('updated_at');
        $at = $at ? Carbon::parse($at) : null;
        $date = DB::table('stocks')->max('stock_date');
        $errors = (int) DB::table('item_sync_days')->where('date', now()->toDateString())->value('error_count');

        return [
            'errors_today' => $errors,
            'at' => $at,
            'ago' => $at?->diffForHumans(),
            'stock_date' => $date ? (string) $date : null,
            'stale' => $at === null || $at->lt(now()->subHours(24)),
        ];
    }

    public function render()
    {
        $tasks = $this->tasks();
        $queue = $this->queue();
        $sync = $this->sync();
        $oneC = $this->oneC();

        $beat = TaskRuns::last(TaskRuns::HEARTBEAT);
        $beatAt = $beat ? Carbon::parse($beat->finished_at) : null;
        $scheduler = ['at' => $beatAt, 'ok' => $beatAt !== null && $beatAt->gte(now()->subMinutes(3))];

        $problems = [];
        if (! $scheduler['ok']) {
            $problems[] = $beatAt === null
                ? __('The scheduler has not reported yet: check the cron job (* * * * * php artisan schedule:run).')
                : __('The scheduler is silent for more than 3 minutes: check the cron job (* * * * * php artisan schedule:run).');
        }
        foreach ($tasks as $t) {
            if ($t['state'] === 'error') {
                $problems[] = $t['name'].': '.__('Error').($t['note'] ? ' - '.Str::limit($t['note'], 120) : '');
            } elseif ($t['state'] === 'overdue') {
                $problems[] = $t['name'].': '.__('Not running');
            }
        }
        if ($queue['workerDead']) {
            $problems[] = __('The queue worker looks stopped: jobs are waiting for more than 2 minutes.');
        }
        if ($queue['growing']) {
            $problems[] = __('Waiting jobs keep growing: the queue worker does not keep up.');
        }
        if (($queue['failedTotal'] ?? 0) > 0) {
            $problems[] = __('Failed jobs').': '.$queue['failedTotal'];
        }
        if ($sync['failed'] > 0) {
            $problems[] = __('Orders that failed to sync to 1C').': '.$sync['failed'];
        }
        if ($sync['urlMissing'] && $sync['queued'] > 0) {
            $problems[] = __('The 1C orders address (ONEC_ORDERS_URL) is not set, orders wait in the queue.');
        }

        return view('livewire.monitoring.tasks', compact('tasks', 'queue', 'sync', 'oneC', 'scheduler', 'problems'));
    }
}
