@php
    $stateMeta = [
        'ok' => ['success', __('Working'), 'check'],
        'error' => ['danger', __('Error'), 'exclamation'],
        'overdue' => ['warning', __('Not running'), 'clock'],
        'never' => ['default', __('Never ran'), 'clock'],
    ];
    $fmtDuration = fn ($ms) => $ms === null ? '—' : ($ms < 1000 ? $ms.' ms' : number_format($ms / 1000, 1).' s');
@endphp
<div wire:poll.15s>
    <x-ui.page-header :title="__('Tasks')" :subtitle="__('Is every background task, the queue and the 1C sync running?')">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('telegram.status')">{{ __('Telegram status') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Overall status --}}
    @if(count($problems) === 0)
        <div class="mb-4 flex items-center gap-3 rounded-lg bg-brand-50 px-4 py-3 text-brand-700 ring-1 ring-inset ring-brand-600/20" data-overall="ok" role="status">
            <x-icon name="check" class="h-6 w-6 shrink-0" />
            <p class="font-semibold">{{ __('All systems running') }}</p>
        </div>
    @else
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-red-700 ring-1 ring-inset ring-red-600/20" data-overall="problems" role="alert">
            <div class="flex items-center gap-3">
                <x-icon name="exclamation" class="h-6 w-6 shrink-0" />
                <p class="font-semibold">{{ __(':count problems', ['count' => count($problems)]) }}</p>
            </div>
            <ul class="mt-2 list-disc space-y-0.5 pl-12 text-sm">
                @foreach($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($output !== '')
        <p class="mb-4 rounded-lg bg-sand-50 px-3 py-2 text-sm text-slate-700" data-command-output>{{ $output }}</p>
    @endif

    {{-- Scheduler + queue + 1C summary --}}
    <div class="mb-4 grid gap-3 md:grid-cols-3">
        <x-ui.card>
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Scheduler') }}</p>
            <p class="mt-1">
                @if($scheduler['ok'])
                    <x-ui.badge variant="success">{{ __('Working') }}</x-ui.badge>
                @elseif($scheduler['at'])
                    <x-ui.badge variant="danger">{{ __('Not running') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="default">{{ __('Never ran') }}</x-ui.badge>
                @endif
            </p>
            <p class="mt-2 text-xs text-slate-500">{{ __('Last heartbeat') }}: {{ $scheduler['at'] ? $scheduler['at']->format('d.m H:i:s').' ('.$scheduler['at']->diffForHumans().')' : '—' }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('The cron job runs: * * * * * php artisan schedule:run') }}</p>
        </x-ui.card>

        <x-ui.card>
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Queue') }}</p>
            <p class="mt-1 text-sm text-slate-700">{{ __('Connection') }}: <span class="font-semibold" data-queue-driver>{{ $queue['driver'] }}</span></p>
            <p class="text-sm text-slate-700">{{ __('Waiting jobs') }}: <span class="font-semibold" data-queue-pending>{{ $queue['pending'] ?? '—' }}</span></p>
            <p class="text-sm text-slate-700">{{ __('Oldest waiting job') }}: <span class="font-semibold">{{ $queue['oldest'] !== null ? $queue['oldest'].' '.__('sec') : '—' }}</span></p>
            <p class="text-sm {{ ($queue['failedTotal'] ?? 0) > 0 ? 'font-medium text-red-700' : 'text-slate-700' }}">{{ __('Failed jobs') }}: <span class="font-semibold" data-queue-failed>{{ $queue['failedTotal'] ?? '—' }}</span></p>
            @if($queue['driver'] === 'sync')
                <p class="mt-2 text-xs text-slate-500">{{ __('Jobs run immediately, there is no worker.') }}</p>
            @endif
            @if($queue['growing'])
                <p class="mt-2 flex items-start gap-1.5 text-xs font-medium text-amber-700" data-queue-growing><x-icon name="exclamation" class="h-4 w-4 shrink-0" />{{ __('Waiting jobs keep growing: the queue worker does not keep up.') }}</p>
            @endif
            @if($queue['workerDead'])
                <p class="mt-2 flex items-start gap-1.5 text-xs font-medium text-red-700" data-worker-dead><x-icon name="exclamation" class="h-4 w-4 shrink-0" />{{ __('The queue worker looks stopped: jobs are waiting for more than 2 minutes.') }} <code>queue:work</code></p>
            @endif
        </x-ui.card>

        <x-ui.card>
            <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Order sync (1C)') }}</p>
            <p class="mt-1 text-sm text-slate-700">{{ __('Queued') }}: <span class="font-semibold" data-sync-queued>{{ $sync['queued'] }}</span></p>
            <p class="text-sm {{ $sync['failed'] > 0 ? 'font-medium text-red-700' : 'text-slate-700' }}">{{ __('Failed') }}: <span class="font-semibold" data-sync-failed>{{ $sync['failed'] }}</span></p>
            <p class="text-sm text-slate-700">{{ __('Synced today') }}: <span class="font-semibold">{{ $sync['syncedToday'] }}</span></p>
            @if($sync['lastError'])
                <p class="mt-2 text-xs text-red-700" data-sync-error>{{ __('Last error') }}: {{ $sync['lastError'] }}</p>
            @endif
            @if($sync['urlMissing'])
                <p class="mt-2 flex items-start gap-1.5 text-xs text-amber-700" data-sync-url-missing><x-icon name="exclamation" class="h-4 w-4 shrink-0" />{{ __('The 1C orders address (ONEC_ORDERS_URL) is not set, orders wait in the queue.') }}</p>
            @endif
            <p class="mt-2 text-sm"><a href="{{ route('orders.index') }}" class="font-medium text-primary-700 hover:underline">{{ __('Go to orders') }}</a></p>
        </x-ui.card>
    </div>

    {{-- Tasks: cards on a phone, table on desktop --}}
    <h2 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Scheduled tasks') }}</h2>
    <div class="space-y-3 md:hidden">
        @foreach($tasks as $t)
            @php [$variant, $label, $icon] = $stateMeta[$t['state']]; @endphp
            <x-ui.card data-task="{{ $t['name'] }}">
                <div class="flex items-start justify-between gap-2">
                    <p class="font-mono text-sm font-medium text-slate-900">{{ $t['name'] }}</p>
                    <x-ui.badge :variant="$variant"><x-icon :name="$icon" class="mr-1 h-3.5 w-3.5" />{{ $label }}</x-ui.badge>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ $t['what'] }}</p>
                <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-600">
                    <dt>{{ __('Schedule') }}</dt><dd>{{ $t['schedule'] }}</dd>
                    <dt>{{ __('Last run') }}</dt><dd>{{ $t['at'] ? $t['at']->format('d.m H:i:s') : '—' }}</dd>
                    <dt>{{ __('Duration') }}</dt><dd>{{ $fmtDuration($t['duration']) }}</dd>
                    <dt>{{ __('Next run') }}</dt><dd>{{ $t['next'] ? $t['next']->format('d.m H:i') : '—' }}</dd>
                </dl>
                @if($t['note'])<p class="mt-2 break-words text-xs {{ $t['state'] === 'error' ? 'text-red-700' : 'text-slate-500' }}">{{ $t['note'] }}</p>@endif
            </x-ui.card>
        @endforeach
    </div>

    <div class="hidden md:block">
        <x-ui.card padding="p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2">{{ __('Task') }}</th>
                        <th class="px-4 py-2">{{ __('Schedule') }}</th>
                        <th class="px-4 py-2">{{ __('Status') }}</th>
                        <th class="px-4 py-2">{{ __('Last run') }}</th>
                        <th class="px-4 py-2">{{ __('Duration') }}</th>
                        <th class="px-4 py-2">{{ __('Next run') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($tasks as $t)
                        @php [$variant, $label, $icon] = $stateMeta[$t['state']]; @endphp
                        <tr data-task="{{ $t['name'] }}">
                            <td class="px-4 py-2 align-top">
                                <p class="font-mono font-medium text-slate-900">{{ $t['name'] }}</p>
                                <p class="text-xs text-slate-500">{{ $t['what'] }}</p>
                            </td>
                            <td class="px-4 py-2 align-top text-slate-600">{{ $t['schedule'] }}</td>
                            <td class="px-4 py-2 align-top">
                                <x-ui.badge :variant="$variant"><x-icon :name="$icon" class="mr-1 h-3.5 w-3.5" />{{ $label }}</x-ui.badge>
                                @if($t['note'])<p class="mt-1 max-w-xs break-words text-xs {{ $t['state'] === 'error' ? 'text-red-700' : 'text-slate-500' }}">{{ $t['note'] }}</p>@endif
                            </td>
                            <td class="px-4 py-2 align-top text-slate-600">{{ $t['at'] ? $t['at']->format('d.m H:i:s') : '—' }}@if($t['at'])<span class="block text-xs text-slate-400">{{ $t['at']->diffForHumans() }}</span>@endif</td>
                            <td class="px-4 py-2 align-top text-slate-600">{{ $fmtDuration($t['duration']) }}</td>
                            <td class="px-4 py-2 align-top text-slate-600">{{ $t['next'] ? $t['next']->format('d.m H:i') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <p class="mt-3 text-xs text-slate-500">{{ __('"Not running" means a scheduled run is missing: check the Laravel scheduler cron (* * * * * php artisan schedule:run) and the queue worker.') }}</p>

    {{-- Failed jobs --}}
    <h2 class="mb-2 mt-6 text-sm font-semibold text-slate-700">{{ __('Last failed jobs') }}</h2>
    @if($queue['failed']->isEmpty())
        <x-ui.card><p class="text-sm text-slate-500" data-no-failed>{{ __('No failed jobs.') }}</p></x-ui.card>
    @else
        <x-ui.card padding="p-0">
            <div class="divide-y divide-slate-100">
                @foreach($queue['failed'] as $f)
                    <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" data-failed-job="{{ $f['uuid'] }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $f['job'] }} <span class="text-xs font-normal text-slate-500">· {{ $f['queue'] }} · {{ $f['at']->format('d.m H:i') }}</span></p>
                            <p class="break-words text-xs text-red-700">{{ $f['error'] }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <x-ui.button variant="secondary" wire:click="retry('{{ $f['uuid'] }}')" wire:loading.attr="disabled">{{ __('Retry') }}</x-ui.button>
                            <x-ui.button variant="danger" wire:click="forget('{{ $f['uuid'] }}')" wire:confirm="{{ __('Remove this failed job for good?') }}">{{ __('Forget') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
