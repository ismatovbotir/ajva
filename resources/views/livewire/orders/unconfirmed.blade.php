@php
    $th = 'px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $sevVariant = ['red' => 'danger', 'amber' => 'warning', 'none' => 'default'];
    $kindLabel = ['draft' => __('Draft not confirmed'), 'receipt' => __('Not received'), 'sync' => __('1C sync'), 'approval' => __('Waiting for approval')];
    $typeLabel = ['all' => __('All'), 'draft' => __('Drafts not confirmed'), 'receipt' => __('Goods not received'), 'sync' => __('1C sync problems'), 'approval' => __('Waiting for approval')];
@endphp
<div>
    <x-ui.page-header :title="__('Not confirmed orders')" :subtitle="__('Drafts nobody confirmed, goods not confirmed as received and stuck 1C syncs. Oldest first.')" />

    @if($noShops)
        <x-ui.no-shops />
    @else
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-56">
                <x-ui.select name="unconfirmed-shop" :label="__('Shop')" wire:model.live="shop">
                    <option value="">{{ __('All shops') }}</option>
                    @foreach($shops as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-64">
                <x-ui.select name="unconfirmed-type" :label="__('Type')" wire:model.live="type">
                    @foreach($types as $t)
                        <option value="{{ $t }}">{{ $typeLabel[$t] }}</option>
                    @endforeach
                </x-ui.select>
            </div>
        </div>

        @if($notice !== '')
            <p class="mb-4 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" data-notice>{{ $notice }}</p>
        @endif

        @if($rows->isEmpty())
            <x-ui.empty-state :title="__('Nothing is waiting')" :description="__('Every draft is confirmed and every confirmed order is received.')" />
        @else
            {{-- Mobile card list --}}
            <div class="space-y-3 md:hidden" data-unconfirmed-cards>
                @foreach($rows as $r)
                    @php $order = $r['order']; @endphp
                    <x-ui.card wire:key="unc-card-{{ $order->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $order->number() }}</p>
                                <p class="text-sm text-slate-600">{{ $order->shop?->name }} · {{ __(':count items', ['count' => $order->items_count]) }}</p>
                            </div>
                            <x-ui.badge :variant="$sevVariant[$r['severity']]" data-severity="{{ $r['severity'] }}">{{ \App\Services\UnconfirmedOrders::ageLabel($r['age_minutes']) }}</x-ui.badge>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($r['kinds'] as $k)<x-ui.badge>{{ $kindLabel[$k] }}</x-ui.badge>@endforeach
                            @if($r['overdue'])<x-ui.badge variant="danger">{{ __('Overdue') }}</x-ui.badge>@endif
                            @if($r['expired'])<x-ui.badge variant="warning">{{ __('Expired') }}</x-ui.badge>@endif
                            @if($order->sync_status->value === 'failed')<x-ui.badge variant="danger">{{ $order->sync_status->label() }}</x-ui.badge>@endif
                        </div>
                        <dl class="mt-3 space-y-1.5 text-sm">
                            <div class="flex items-start justify-between gap-2">
                                <dt class="text-slate-500">{{ __('Responsible') }}</dt>
                                <dd class="text-right text-slate-700">{{ $r['people']->pluck('name')->implode(', ') ?: '—' }}</dd>
                            </div>
                            @if(in_array('draft', $r['kinds'], true))
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-slate-500">{{ __('Link') }}</dt>
                                    <dd class="text-slate-700">{{ $r['opened_at'] ? __('Opened :time', ['time' => \Illuminate\Support\Carbon::parse($r['opened_at'])->format('d.m H:i')]) : __('Not opened') }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-slate-500">{{ __('Telegram') }}</dt>
                                    <dd>@include('livewire.orders.partials.telegram-badge', ['order' => $order])</dd>
                                </div>
                            @endif
                        </dl>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <x-ui.button :href="route('orders.show', $order)" variant="secondary">{{ __('Open') }}</x-ui.button>
                            @if($isAdmin && ! in_array('approval', $r['kinds'], true))
                                <x-ui.button type="button" variant="secondary" wire:click="sendReminder({{ $order->id }})" wire:loading.attr="disabled" wire:target="sendReminder({{ $order->id }})" data-remind>{{ __('Send reminder now') }}</x-ui.button>
                            @endif
                        </div>
                    </x-ui.card>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block">
                <x-ui.card padding="p-0">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="{{ $th }} text-left">{{ __('Number') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Shop') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Problem') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Age') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Responsible') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Link') }}</th>
                                    <th class="{{ $th }} text-left">{{ __('Telegram') }}</th>
                                    <th class="{{ $th }}"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($rows as $r)
                                    @php $order = $r['order']; @endphp
                                    <tr wire:key="unc-{{ $order->id }}">
                                        <td class="px-3 py-3 text-sm font-medium text-slate-900">{{ $order->number() }}</td>
                                        <td class="px-3 py-3 text-sm text-slate-600">{{ $order->shop?->name }}</td>
                                        <td class="px-3 py-3 text-sm">
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach($r['kinds'] as $k)<x-ui.badge>{{ $kindLabel[$k] }}</x-ui.badge>@endforeach
                                                @if($r['overdue'])<x-ui.badge variant="danger">{{ __('Overdue') }}</x-ui.badge>@endif
                                                @if($r['expired'])<x-ui.badge variant="warning">{{ __('Expired') }}</x-ui.badge>@endif
                                                @if($order->sync_status->value === 'failed')<x-ui.badge variant="danger">{{ $order->sync_status->label() }}</x-ui.badge>@endif
                                            </div>
                                        </td>
                                        <td class="px-3 py-3 text-sm"><x-ui.badge :variant="$sevVariant[$r['severity']]" data-severity="{{ $r['severity'] }}">{{ \App\Services\UnconfirmedOrders::ageLabel($r['age_minutes']) }}</x-ui.badge></td>
                                        <td class="px-3 py-3 text-sm text-slate-600">{{ $r['people']->pluck('name')->implode(', ') ?: '—' }}</td>
                                        <td class="px-3 py-3 text-sm text-slate-600">
                                            @if(in_array('draft', $r['kinds'], true))
                                                {{ $r['opened_at'] ? __('Opened :time', ['time' => \Illuminate\Support\Carbon::parse($r['opened_at'])->format('d.m H:i')]) : __('Not opened') }}
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-sm">
                                            @if(in_array('draft', $r['kinds'], true))
                                                @include('livewire.orders.partials.telegram-badge', ['order' => $order])
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-right text-sm">
                                            <a href="{{ route('orders.show', $order) }}" class="mr-2 font-medium text-brand-700 hover:underline">{{ __('Open') }}</a>
                                            @if($isAdmin && ! in_array('approval', $r['kinds'], true))
                                                <x-ui.button type="button" variant="secondary" wire:click="sendReminder({{ $order->id }})" wire:loading.attr="disabled" wire:target="sendReminder({{ $order->id }})" data-remind>{{ __('Send reminder now') }}</x-ui.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            </div>
        @endif
    @endif
</div>
