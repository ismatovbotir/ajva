<?php

namespace App\Console\Commands;

use App\Services\OrderTelegram;
use App\Services\SecurityWarnings;
use App\Support\PublicUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SecurityDigest extends Command
{
    protected $signature = 'security:digest';

    protected $description = 'Telegram notice to the admins: how many HIGH security warnings since yesterday (once a day, nothing when none)';

    public function handle(SecurityWarnings $security, OrderTelegram $telegram): int
    {
        if (! $telegram->configured()) {
            return self::SUCCESS;
        }

        $admins = OrderTelegram::admins();
        if ($admins->isEmpty()) {
            return self::SUCCESS;
        }

        $shopIds = DB::table('shops')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $high = $security->build(now()->subDay(), now(), $shopIds)['kpi']['high'];
        if ($high === 0) {
            return self::SUCCESS;
        }

        $message = __('High security warnings since yesterday: :count. Please review them.', ['count' => $high]);
        $url = PublicUrl::to(route('security.warnings', [], false));
        $sent = 0;

        foreach ($admins as $admin) {
            // One notice per admin per day: the claim goes in BEFORE sending and is dropped again when the send fails.
            $key = 'security.digest.'.now()->toDateString().'.'.$admin->id;
            if (! Cache::add($key, 1, now()->addDays(2))) {
                continue;
            }
            $res = $telegram->notify(null, $admin, $message, __('Open'), $url);
            if (($res['ok'] ?? false) === true) {
                $sent++;
            } else {
                Cache::forget($key);
            }
        }

        $this->line("High warnings: {$high}, sent: {$sent}.");

        return self::SUCCESS;
    }
}
