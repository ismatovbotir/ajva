<?php

namespace App\Console;

use App\Support\TaskRuns;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Daily draft orders per shop (Settings > Order rules): checks every 5 minutes which shops are due.
        // Needs the Laravel scheduler cron (* * * * * php artisan schedule:run) and a queue worker.
        $schedule->command('orders:auto-generate')->everyFiveMinutes()->withoutOverlapping();

        // Goods never confirmed as received stop counting as in transit (flag only, nothing invented).
        $schedule->command('orders:expire-transit')->hourly()->withoutOverlapping();

        // Telegram reminders: unconfirmed drafts, failed 1C syncs, goods not received, daily stock-out digest.
        $schedule->command('orders:send-reminders')->everyTenMinutes()->withoutOverlapping();

        // Security digest: one short Telegram notice a day to the admins when receipts raised HIGH warnings (Security > Security warnings).
        $schedule->command('security:digest')->dailyAt(config('security.digest_time', '08:45'))->withoutOverlapping();

        // Bot messages (Telegram > Users / Messages): one getUpdates pass per minute, no webhook.
        $schedule->command('telegram:poll')->everyMinute()->withoutOverlapping();

        // Queue worker driven by the scheduler (the server only runs the cron): works the database queue for up to 55 s
        // every minute. runInBackground so it never delays the other tasks; withoutOverlapping so a long job (up to its own
        // timeout, max 80 s < retry_after 90) is not doubled by the next minute's worker. Job tries/backoff/timeout
        // are the jobs' own properties and win over these defaults.
        $schedule->command('queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=60')
            ->everyMinute()->withoutOverlapping(10)->runInBackground();

        // Heartbeat for Monitoring > Tasks: proves the scheduler cron itself runs.
        $schedule->call(fn () => TaskRuns::heartbeat())->everyMinute()->name(TaskRuns::HEARTBEAT);
    }

    /** Adds the run-log hooks (Monitoring > Tasks) to every scheduled command, whichever file defines it. */
    protected function defineConsoleSchedule()
    {
        parent::defineConsoleSchedule();

        $this->app->afterResolving(Schedule::class, fn (Schedule $schedule) => TaskRuns::track($schedule));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
