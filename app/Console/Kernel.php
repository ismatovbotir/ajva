<?php

namespace App\Console;

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

        // Telegram bot messages (users pressing Start on their connect link): a quick getUpdates pass every minute, so
        // linking works with cron alone. A long-running `php artisan telegram:poll` can be used instead (shared lock).
        $schedule->command('telegram:poll --once')->everyMinute()->withoutOverlapping();
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
