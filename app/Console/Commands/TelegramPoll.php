<?php

namespace App\Console\Commands;

use App\Services\TelegramUpdates;
use Illuminate\Console\Command;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Fetch new bot messages with getUpdates and store telegram users and messages (scheduled every minute)';

    public function handle(TelegramUpdates $updates): int
    {
        $result = $updates->poll();

        match ($result['status']) {
            'ok' => $this->line("Stored {$result['messages']} new message(s)."),
            'skipped' => $this->line('Another poll is running.'),
            'no_token' => $this->warn('TELEGRAM_BOT_TOKEN is not set.'),
            default => $this->error('Telegram error: '.$result['error']),
        };

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
