<div>
    <x-ui.page-header :title="__('Items sync log')" :subtitle="__('Item data pushed by 1C: one row per day with the update time, and every load error.')">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('monitoring.tasks')">{{ __('Tasks') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <h2 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Last 30 days') }}</h2>
    @if($days->isEmpty())
        <x-ui.card><p class="text-sm text-slate-500" data-no-days>{{ __('Nothing has been logged yet.') }}</p></x-ui.card>
    @else
        <x-ui.card padding="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2">{{ __('Date') }}</th>
                            <th class="px-4 py-2">{{ __('Last update') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Batches') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Items') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Errors') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($days as $d)
                            <tr data-day="{{ $d->date }}">
                                <td class="px-4 py-2 text-slate-900">{{ \Illuminate\Support\Carbon::parse($d->date)->format('d.m.Y') }}</td>
                                <td class="px-4 py-2 text-slate-600">{{ $d->last_success_at ? $d->last_success_at->format('H:i:s') : '—' }}</td>
                                <td class="px-4 py-2 text-right text-slate-600">{{ $d->batches }}</td>
                                <td class="px-4 py-2 text-right text-slate-600">{{ $d->items }}</td>
                                <td class="px-4 py-2 text-right {{ $d->error_count > 0 ? 'font-semibold text-red-700' : 'text-slate-600' }}">{{ $d->error_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    @if(session('status'))
        <p class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" data-cleared>{{ session('status') }}</p>
    @endif
    <div class="mb-2 mt-6 flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-700">{{ __('Latest errors') }}</h2>
        @if(! $errors->isEmpty())
            <x-ui.button variant="danger" wire:click="clearLog" wire:confirm="{{ __('Delete all items sync errors? The daily update times stay.') }}" data-clear-log>{{ __('Clear log') }}</x-ui.button>
        @endif
    </div>
    @if($errors->isEmpty())
        <x-ui.card><p class="text-sm text-slate-500" data-no-errors>{{ __('No errors.') }}</p></x-ui.card>
    @else
        <x-ui.card padding="p-0">
            <div class="divide-y divide-slate-100">
                @foreach($errors as $e)
                    <div class="px-4 py-3" data-error>
                        <p class="text-sm font-medium text-slate-900">{{ $e->occurred_at->format('d.m.Y H:i:s') }}
                            <x-ui.badge variant="danger">{{ $e->stage }}</x-ui.badge>
                            @if($e->item_id)<span class="text-xs font-normal text-slate-500">· {{ __('Item') }} #{{ $e->item_id }}</span>@endif
                            @if($e->shop_id)<span class="text-xs font-normal text-slate-500">· {{ __('Shop') }} #{{ $e->shop_id }}</span>@endif
                        </p>
                        <p class="break-words text-xs text-red-700">{{ $e->message }}</p>
                        @if($e->excerpt || $e->batch_size)
                            <p class="text-xs text-slate-500">{{ $e->excerpt }}@if($e->batch_size) · {{ __('Batch size') }}: {{ $e->batch_size }}@endif</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
