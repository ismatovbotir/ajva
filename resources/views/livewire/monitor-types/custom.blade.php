{{-- Custom monitor: the widget grid of the saved layout, rendered inside livewire/monitor-screen.blade.php.
     Data: $custom = App\Monitor\MonitorData::build() (profit and receipt-level widgets are already absent when not allowed). --}}
<div class="min-h-0 flex-1 overflow-y-auto">
    <x-monitor.grid :widgets="$custom['widgets']" :rows="$monitor->layout_rows" />
</div>
