<?php

namespace Tests\Concerns;

use App\Livewire\MonitorScreen;
use App\Models\Monitor;
use App\Models\User;
use Livewire\Livewire;

trait InteractsWithMonitors
{
    /**
     * An enabled executive monitor; $shops = shops it shows (empty = all).
     *
     * @param  array<int, int>  $shops
     */
    protected function executiveMonitor(array $shops = [], array $attributes = []): Monitor
    {
        $monitor = Monitor::factory()->create($attributes);
        $monitor->shops()->sync($shops);

        return $monitor;
    }

    /** Mounts a screen component (MonitorScreen gets the first / an all-shops executive monitor), signed in as $user if given. */
    protected function screen(string $class, ?User $user = null)
    {
        $params = $class === MonitorScreen::class
            ? ['monitor' => Monitor::query()->first() ?? $this->executiveMonitor()]
            : [];

        return $user ? Livewire::actingAs($user)->test($class, $params) : Livewire::test($class, $params);
    }
}
