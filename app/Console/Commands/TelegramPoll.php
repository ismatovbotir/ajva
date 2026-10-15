<?php

namespace App\Console\Commands;

use App\Services\TelegramUpdates;
use Illuminate\Console\Command;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : one quick pass and exit (the scheduler runs this every minute)}';

    protected $description = 'Fetch the Telegram bot messages (getUpdates) and connect users who pressed Start on their link';

    public function handle(TelegramUpdates $updates): int
    {
        if ($this->option('once')) {
            $result = $updates->poll(0);
            $this->line($result['skipped'] ? 'Another poller is running.' : sprintf('Handled %d update(s).%s', $result['handled'], $result['error'] ? ' Error: '.$result['error'] : ''));

            return self::SUCCESS;
        }

        // Long-running mode: long-poll 25 s per request, back off on errors (the offset is never lost).
        $wait = 2;
        while (true) {
            $result = $updates->poll(25);
            if ($result['error'] === 'no_token') {
                $this->error('TELEGRAM_BOT_TOKEN is not set.');

                return self::FAILURE;
            }
            if ($result['error'] !== null) {
                $this->warn('Error: '.$result['error'].' - retrying in '.$wait.' s');
                sleep($wait);
                $wait = min($wait * 2, 60);
            } else {
                $wait = 2;
                if ($result['skipped']) {
                    sleep(5);
                } elseif ($result['handled'] > 0) {
                    $this->line('Handled '.$result['handled'].' update(s).');
                }
            }
        }
    }
}
