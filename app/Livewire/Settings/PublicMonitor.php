<?php

namespace App\Livewire\Settings;

use App\Support\MonitorSettings;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Public monitor'])]
class PublicMonitor extends Component
{
    /**
     * Runs on every request, including Livewire updates, which skip the route
     * middleware - so a demoted admin can't keep using an already-open page.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function toggleEnabled(MonitorSettings $settings): void
    {
        $settings->setEnabled(! $settings->enabled());
    }

    public function toggleProfit(MonitorSettings $settings): void
    {
        $settings->setShowProfit(! $settings->showProfit());
    }

    public function generateToken(MonitorSettings $settings): void
    {
        $settings->generateToken();
    }

    public function render(MonitorSettings $settings)
    {
        $token = $settings->token();

        return view('livewire.settings.public-monitor', [
            'enabled' => $settings->enabled(),
            'showProfit' => $settings->showProfit(),
            'hasToken' => $token !== null,
            'link' => $token ? route('monitor.public', ['token' => $token]) : null,
            'tokenCreatedAt' => $settings->tokenCreatedAt(),
        ]);
    }
}
