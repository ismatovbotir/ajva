<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Run log of the background tasks (Monitoring > Tasks): one row per finished run in `task_runs`, the newest
 * KEEP rows per task are kept. Scheduled commands are recorded without touching them: Console\Kernel calls
 * track() once, which adds start/success/failure hooks to every scheduled command.
 */
class TaskRuns
{
    public const KEEP = 50;

    /** Name of the heartbeat row written by the scheduler every minute. */
    public const HEARTBEAT = 'scheduler:heartbeat';

    public static function record(string $name, string $status, ?string $note = null, ?int $durationMs = null, ?Carbon $startedAt = null): void
    {
        $now = now();
        $id = DB::table('task_runs')->insertGetId([
            'name' => $name,
            'started_at' => $startedAt ?? ($durationMs !== null ? $now->copy()->subMilliseconds($durationMs) : $now),
            'finished_at' => $now,
            'status' => $status === 'ok' ? 'ok' : 'error',
            'note' => $note !== null ? mb_substr($note, 0, 1000) : null,
            'duration_ms' => $durationMs,
        ]);

        $cut = DB::table('task_runs')->where('name', $name)->orderByDesc('id')->skip(self::KEEP)->take(1)->value('id');
        if ($cut !== null) {
            DB::table('task_runs')->where('name', $name)->where('id', '<=', $cut)->delete();
        }
    }

    /** @return object|null the newest run: name, started_at, finished_at (strings), status, note, duration_ms */
    public static function last(string $name): ?object
    {
        return DB::table('task_runs')->where('name', $name)->orderByDesc('id')->first();
    }

    /** The artisan command name of a scheduled event, or null for closures. */
    public static function commandName(Event $event): ?string
    {
        return preg_match('/artisan[\'"]?\s+([\w:\-]+)/', (string) ($event->command ?? ''), $m) === 1 ? $m[1] : null;
    }

    /** Adds the recording hooks to every scheduled command (called from Console\Kernel, so new commands are covered). */
    public static function track(Schedule $schedule): void
    {
        $dir = storage_path('app/task-output');
        $started = [];

        foreach ($schedule->events() as $event) {
            $name = self::commandName($event);
            if ($name === null) {
                continue;
            }

            $file = $dir.DIRECTORY_SEPARATOR.str_replace(':', '-', $name).'.log';
            if ($event->output === $event->getDefaultOutput()) {
                $event->sendOutputTo($file);
            }

            $event->before(function () use (&$started, $name) {
                $started[$name] = now();
            });
            $event->onSuccess(function () use (&$started, $name) {
                self::safely(fn () => self::record($name, 'ok', null, self::elapsed($started, $name), $started[$name] ?? null));
            });
            $event->onFailure(function () use (&$started, $name, $event) {
                $note = 'exit code '.(int) $event->exitCode;
                $tail = is_file((string) $event->output) ? trim((string) file_get_contents($event->output)) : '';
                if ($tail !== '') {
                    $note .= ': '.mb_substr($tail, -400);
                }
                self::safely(fn () => self::record($name, 'error', $note, self::elapsed($started, $name), $started[$name] ?? null));
            });
        }
    }

    /** Never lets a logging problem (e.g. table not migrated yet) break the scheduler. */
    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function elapsed(array $started, string $name): ?int
    {
        return isset($started[$name]) ? (int) $started[$name]->diffInMilliseconds(now()) : null;
    }

    public static function heartbeat(): void
    {
        // The note carries the waiting-jobs count, so the board can warn when the queue keeps growing.
        self::safely(fn () => self::record(self::HEARTBEAT, 'ok', Schema::hasTable('jobs') ? 'pending='.DB::table('jobs')->count() : null));
    }
}
