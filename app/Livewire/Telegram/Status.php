<?php

namespace App\Livewire\Telegram;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderRule;
use App\Models\Telegram;
use App\Models\TelegramMessage;
use App\Services\Telegram as TelegramApi;
use App\Support\TelegramStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Telegram status'])]
class Status extends Component
{
    /** Runs on every request, including Livewire updates (they skip route middleware). */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /** Output of the command the admin ran last from this page. */
    public string $output = '';

    /** Runs one poll right now (the same command the scheduler runs every minute). */
    public function pollNow(): void
    {
        Artisan::call('telegram:poll');
        $run = TelegramStatus::get('poll');

        $this->output = match ($run['status'] ?? null) {
            'ok' => __('New messages').': '.($run['messages'] ?? 0),
            'no_token' => __('The Telegram bot token (TELEGRAM_BOT_TOKEN) is not set.'),
            'error' => (string) ($run['error'] ?? 'error'),
            default => __('Another poll is running.'),
        };
    }

    /** Sends the links of the drafts that were not delivered yet (same command as the Send buttons, all such drafts). */
    public function sendNow(): void
    {
        Artisan::call('orders:send-telegram');
        $run = TelegramStatus::get('send');

        $this->output = ($run['orders'] ?? 0) === 0
            ? __('No draft orders to send.')
            : __('Orders').': '.$run['orders'].' · '.__('Sent').': '.$run['sent'].(($run['failed'] ?? false) ? ' · '.__('Failed') : '');
    }

    /** ok | stale | error | never, from the last recorded run and how often the scheduler should run it. */
    private function health(?array $run, int $staleAfterMinutes): string
    {
        if ($run === null) {
            return 'never';
        }
        if (in_array($run['status'] ?? null, ['error', 'no_token'], true) || ($run['failed'] ?? false) === true) {
            return 'error';
        }

        return $run['at']->lt(now()->subMinutes($staleAfterMinutes)) ? 'stale' : 'ok';
    }

    public function render()
    {
        $poll = TelegramStatus::get('poll');
        $send = TelegramStatus::get('send');
        $auto = OrderRule::query()->whereNotNull('last_run_date')->orderByDesc('last_run_date')->first(['last_run_date', 'last_run_status', 'last_run_note']);
        $since = now()->subDay();

        $unsent = Order::query()->where('status', OrderStatus::Draft->value)
            ->where(fn ($q) => $q->whereNull('telegram_status')->orWhereIn('telegram_status', ['failed', 'no_token', 'no_recipients']))
            ->count();

        return view('livewire.telegram.status', [
            'botConfigured' => app(TelegramApi::class)->configured(),
            'botUsername' => TelegramApi::botUsername(),
            'offset' => DB::table('settings')->where('key', 'telegram.offset')->value('value'),
            'commands' => [
                ['name' => 'telegram:poll', 'what' => __('Fetches new bot messages'), 'schedule' => __('every minute'), 'run' => $poll, 'health' => $this->health($poll, 3)],
                ['name' => 'orders:send-telegram', 'what' => __('Sends order links to managers'), 'schedule' => __('on demand (Send buttons)'), 'run' => $send, 'health' => $send === null ? 'never' : $this->health($send, 525600)],
                ['name' => 'orders:auto-generate', 'what' => __('Creates the daily drafts and sends them'), 'schedule' => __('every 5 minutes'), 'run' => null, 'auto' => $auto, 'health' => $auto === null ? 'never' : (in_array($auto->last_run_status, ['error'], true) ? 'error' : 'ok')],
            ],
            'stats' => [
                'users' => Telegram::query()->count(),
                'in24' => TelegramMessage::query()->where('direction', 'in')->where('sent_at', '>=', $since)->count(),
                'out24' => TelegramMessage::query()->where('direction', 'out')->where('status', 'sent')->where('sent_at', '>=', $since)->count(),
                'failed24' => TelegramMessage::query()->where('direction', 'out')->where('status', 'failed')->where('sent_at', '>=', $since)->count(),
                'unsent' => $unsent,
                'queue' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
                'failedJobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            ],
        ]);
    }
}
