<div wire:poll.15s>
    <x-ui.page-header :title="__('Telegram status')" :subtitle="__('Is the bot connected and are the Telegram commands running?')">
        <x-slot:actions>
            <x-ui.button wire:click="pollNow" wire:loading.attr="disabled" wire:target="pollNow">{{ __('Run poll now') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.card>
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Bot') }}</p>
            <p class="mt-1">
                @if($botConfigured)
                    <x-ui.badge variant="success">{{ __('Token set') }}</x-ui.badge>
                    @if($botUsername !== '')<span class="ml-1 text-sm text-slate-600">{{ '@'.$botUsername }}</span>@endif
                @else
                    <x-ui.badge variant="danger">{{ __('No token') }}</x-ui.badge>
                @endif
            </p>
            <p class="mt-2 text-xs text-slate-500">{{ __('Last update offset') }}: {{ $offset ?? '—' }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Last 24 hours') }}</p>
            <p class="mt-1 text-sm text-slate-700">{{ __('Received') }}: <span class="font-semibold">{{ $stats['in24'] }}</span></p>
            <p class="text-sm text-slate-700">{{ __('Sent') }}: <span class="font-semibold">{{ $stats['out24'] }}</span></p>
            <p class="text-sm {{ $stats['failed24'] > 0 ? 'text-red-600' : 'text-slate-700' }}">{{ __('Failed') }}: <span class="font-semibold">{{ $stats['failed24'] }}</span></p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('People and orders') }}</p>
            <p class="mt-1 text-sm text-slate-700">{{ __('Telegram users') }}: <span class="font-semibold">{{ $stats['users'] }}</span></p>
            <p class="text-sm {{ $stats['unsent'] > 0 ? 'text-amber-700' : 'text-slate-700' }}">{{ __('Drafts not delivered') }}: <span class="font-semibold">{{ $stats['unsent'] }}</span></p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Queue') }}</p>
            <p class="mt-1 text-sm text-slate-700">{{ __('Waiting jobs') }}: <span class="font-semibold">{{ $stats['queue'] ?? '—' }}</span></p>
            <p class="text-sm {{ ($stats['failedJobs'] ?? 0) > 0 ? 'text-red-600' : 'text-slate-700' }}">{{ __('Failed jobs') }}: <span class="font-semibold">{{ $stats['failedJobs'] ?? '—' }}</span></p>
        </x-ui.card>
    </div>

    <x-ui.card padding="p-0">
        <div class="divide-y divide-slate-100">
            @foreach($commands as $c)
                @php
                    $variant = ['ok' => 'success', 'error' => 'danger', 'stale' => 'warning', 'never' => 'default'][$c['health']] ?? 'default';
                    $label = ['ok' => __('Working'), 'error' => __('Error'), 'stale' => __('Not running'), 'never' => __('Never ran')][$c['health']];
                @endphp
                <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" data-command="{{ $c['name'] }}">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-medium text-slate-900">php artisan {{ $c['name'] }}</p>
                        <p class="text-xs text-slate-500">{{ $c['what'] }} · {{ $c['schedule'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <x-ui.badge :variant="$variant">{{ $label }}</x-ui.badge>
                        @if($c['run'])
                            <span class="text-xs text-slate-500">{{ $c['run']['at']->format('d.m H:i:s') }} ({{ $c['run']['at']->diffForHumans() }})</span>
                            @if($c['name'] === 'telegram:poll')
                                <span class="text-xs text-slate-500">{{ __('New messages') }}: {{ $c['run']['messages'] ?? 0 }}</span>
                                @if(! empty($c['run']['error']))<span class="text-xs text-red-600">{{ $c['run']['error'] }}</span>@endif
                                @if(($c['run']['status'] ?? '') === 'no_token')<span class="text-xs text-red-600">{{ __('The Telegram bot token (TELEGRAM_BOT_TOKEN) is not set.') }}</span>@endif
                            @else
                                <span class="text-xs text-slate-500">{{ __('Orders') }}: {{ $c['run']['orders'] ?? 0 }} · {{ __('Sent') }}: {{ $c['run']['sent'] ?? 0 }}</span>
                            @endif
                        @elseif(! empty($c['auto']))
                            <span class="text-xs text-slate-500">{{ $c['auto']->last_run_date->format('d.m.Y') }} · {{ $c['auto']->last_run_status }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <p class="mt-3 text-xs text-slate-500">{{ __('"Not running" means the command has not run for several minutes: check the Laravel scheduler cron (* * * * * php artisan schedule:run) and the queue worker.') }}</p>
</div>
