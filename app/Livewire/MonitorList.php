<?php

namespace App\Livewire;

use App\Models\Monitor;
use Livewire\Component;

/**
 * Signed-in /monitor: the monitors the user may open. With exactly one, go
 * straight to it (monitor-only accounts land here after login).
 */
class MonitorList extends Component
{
    public function mount()
    {
        $monitors = Monitor::openableBy(auth()->user());

        if ($monitors->count() === 1) {
            return $this->redirectRoute('monitors.show', ['monitor' => $monitors->first()->id]);
        }
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.monitor-list', [
            'monitors' => Monitor::openableBy($user),
            'isAdmin' => $user->isAdmin(),
            'canUsePanel' => $user->canUsePanel(),
        ])->layout('components.layouts.monitor', ['title' => __('Monitors'), 'public' => false]);
    }
}
