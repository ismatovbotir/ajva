@php
    $th = 'px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $statusVariant = ['draft' => 'warning', 'pending_approval' => 'warning', 'approved' => 'info', 'confirmed' => 'info', 'rejected' => 'danger', 'fulfilled' => 'success', 'cancelled' => 'default'];
@endphp
<div>
    <x-ui.page-header :title="__('Orders')" :subtitle="__('Orders to the main warehouse and their sync with 1C.')" />

    @if(! $onecConfigured)
        <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-no-onec>{{ __('The 1C orders address is not set (ONEC_ORDERS_URL). Confirmed orders stay queued until it is set.') }}</p>
    @endif

    @if($noShops)
        <x-ui.no-shops />
    @elseif($orders->isEmpty())
        <x-ui.empty-state :title="__('No orders yet')" :description="__('Orders created on the Ordering page appear here.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden" data-order-cards>
            @foreach($orders as $order)
                <x-ui.card wire:key="order-card-{{ $order->id }}">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">{{ $order->number() }}</p>
                            <p class="text-sm text-slate-600">{{ $order->shop?->name }} · {{ __(':count items', ['count' => $order->items_count]) }}</p>
                        </div>
                        <x-ui.badge :variant="$statusVariant[$order->status->value] ?? 'default'">{{ $order->status->label() }}</x-ui.badge>
                    </div>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        @if($order->sync_status->value !== 'not_queued')
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-slate-500">{{ __('1C sync') }}</dt>
                                <dd><x-ui.badge :variant="$order->sync_status->badgeVariant()">{{ $order->sync_status->label() }}</x-ui.badge></dd>
                            </div>
                            @if($order->sync_error)<p class="truncate text-xs text-red-600" title="{{ $order->sync_error }}">{{ $order->sync_error }}</p>@endif
                        @endif
                        @if($order->status->value === 'draft')
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-slate-500">{{ __('Telegram') }}</dt>
                                <dd>@include('livewire.orders.partials.telegram-badge', ['order' => $order])</dd>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500">{{ __('Created') }}</dt>
                            <dd class="text-slate-600">{{ $order->created_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                    </dl>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-ui.button :href="route('orders.show', $order)" variant="secondary">{{ $order->status->value === 'draft' ? __('Edit') : __('Open') }}</x-ui.button>
                        @if($isAdmin && $order->status->value === 'draft')
                            @if(($receivers[$order->shop_id] ?? 0) > 0)
                                <x-ui.button type="button" variant="secondary" wire:click="sendTelegram({{ $order->id }})" wire:loading.attr="disabled" wire:target="sendTelegram({{ $order->id }})" data-resend>{{ $order->telegram_status === null ? __('Send') : __('Resend') }}</x-ui.button>
                            @else
                                <span class="text-xs text-slate-400" data-no-receiver>{{ __('No receiver') }}</span>
                            @endif
                        @endif
                        @if($isAdmin && in_array($order->status->value, ['pending_approval', 'approved'], true))
                            <x-ui.button type="button" wire:click="confirm({{ $order->id }})" wire:confirm="{{ __('Confirm this order and send it to 1C?') }}">{{ __('Confirm') }}</x-ui.button>
                        @elseif($isAdmin && $order->status->value === 'confirmed' && in_array($order->sync_status->value, ['failed', 'queued'], true))
                            <x-ui.button type="button" variant="secondary" wire:click="retrySync({{ $order->id }})">{{ __('Retry sync') }}</x-ui.button>
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
                            <th class="{{ $th }} text-right">{{ __('Items') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Status') }}</th>
                            <th class="{{ $th }} text-left">{{ __('1C sync') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Telegram') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Created') }}</th>
                            <th class="{{ $th }}"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($orders as $order)
                            <tr wire:key="order-{{ $order->id }}">
                                <td class="px-3 py-3 text-sm font-medium text-slate-900">{{ $order->number() }}</td>
                                <td class="px-3 py-3 text-sm text-slate-600">{{ $order->shop?->name }}</td>
                                <td class="px-3 py-3 text-right text-sm tabular-nums">{{ $order->items_count }}</td>
                                <td class="px-3 py-3 text-sm"><x-ui.badge :variant="$statusVariant[$order->status->value] ?? 'default'">{{ $order->status->label() }}</x-ui.badge></td>
                                <td class="px-3 py-3 text-sm">
                                    @if($order->sync_status->value !== 'not_queued')
                                        <x-ui.badge :variant="$order->sync_status->badgeVariant()">{{ $order->sync_status->label() }}</x-ui.badge>
                                        @if($order->sync_attempts > 0)<span class="ml-1 text-xs text-slate-400">{{ __(':count attempts', ['count' => $order->sync_attempts]) }}</span>@endif
                                        @if($order->sync_status->value === 'synced' && $order->synced_at)<span class="block text-xs text-slate-400">{{ $order->synced_at->format('Y-m-d H:i') }}</span>@endif
                                        @if($order->sync_error)<span class="block max-w-xs truncate text-xs text-red-600" title="{{ $order->sync_error }}">{{ $order->sync_error }}</span>@endif
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm" data-telegram-cell>
                                    @if($order->status->value === 'draft')
                                        @include('livewire.orders.partials.telegram-badge', ['order' => $order])
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-slate-500">{{ $order->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-3 text-right text-sm">
                                    <a href="{{ route('orders.show', $order) }}" class="mr-2 font-medium text-brand-700 hover:underline">{{ $order->status->value === 'draft' ? __('Edit') : __('Open') }}</a>
                                    @if($isAdmin && $order->status->value === 'draft')
                                        @if(($receivers[$order->shop_id] ?? 0) > 0)
                                            <x-ui.button type="button" variant="secondary" wire:click="sendTelegram({{ $order->id }})" wire:loading.attr="disabled" wire:target="sendTelegram({{ $order->id }})" data-resend>{{ $order->telegram_status === null ? __('Send') : __('Resend') }}</x-ui.button>
                                        @else
                                            <span class="text-xs text-slate-400" data-no-receiver>{{ __('No receiver') }}</span>
                                        @endif
                                    @endif
                                    @if($isAdmin && in_array($order->status->value, ['pending_approval', 'approved'], true))
                                        <x-ui.button type="button" wire:click="confirm({{ $order->id }})" wire:confirm="{{ __('Confirm this order and send it to 1C?') }}">{{ __('Confirm') }}</x-ui.button>
                                    @elseif($isAdmin && $order->status->value === 'confirmed' && in_array($order->sync_status->value, ['failed', 'queued'], true))
                                        <x-ui.button type="button" variant="secondary" wire:click="retrySync({{ $order->id }})">{{ __('Retry sync') }}</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
        </div>
        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
