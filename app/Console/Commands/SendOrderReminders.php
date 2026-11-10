<?php

namespace App\Console\Commands;

use App\Services\OrderReminders;
use App\Support\TelegramStatus;
use Illuminate\Console\Command;

class SendOrderReminders extends Command
{
    protected $signature = 'orders:send-reminders';

    protected $description = 'Telegram reminders: unconfirmed drafts, failed 1C syncs, goods not received, daily stock-out digest (each sent once)';

    public function handle(OrderReminders $reminders): int
    {
        $stats = $reminders->run();
        $this->line(sprintf('Reminders sent: %d, failed: %d.', $stats['sent'], $stats['failed']));
        TelegramStatus::record('reminders', $stats);

        return self::SUCCESS;
    }
}
