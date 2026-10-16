@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
    $th = 'px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp
<div>
    <x-ui.page-header :title="$order->number().' · '.($order->shop?->name)" :subtitle="__('Order to the main warehouse')" />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('orders.index') }}" class="text-sm font-medium text-brand-700 hover:underline">← {{ __('Orders') }}</a>
        <x-ui.badge :variant="$order->status === \App\Enums\OrderStatus::Draft ? 'warning' : 'info'">{{ $order->status->label() }}</x-ui.badge>
        @if($order->sync_status->value !== 'not_queued')
            <x-ui.badge :variant="$order->sync_status->badgeVariant()">{{ $order->sync_status->label() }}</x-ui.badge>
            @if($order->sync_attempts > 0)<span class="text-xs text-slate-400">{{ __(':count attempts', ['count' => $order->sync_attempts]) }}</span>@endif
            @if($order->sync_error)<span class="text-xs text-red-600">{{ $order->sync_error }}</span>@endif
        @endif
        @if($order->isAuto())<x-ui.badge>{{ __('Automatic') }}</x-ui.badge>@endif
        @if($order->horizon_days)<span class="text-xs text-slate-500">{{ __('Cover, days') }}: {{ $order->horizon_days }}</span>@endif
    </div>

    @if(! $onecConfigured && $order->status !== \App\Enums\OrderStatus::Draft)
        <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('The 1C orders address is not set (ONEC_ORDERS_URL). Confirmed orders stay queued until it is set.') }}</p>
    @endif
    @if($canEdit)
        <p class="mb-3 text-sm text-slate-500">{{ __('This order is a draft: change the quantities, remove lines or add items. After confirming it is locked.') }}</p>
    @endif
    @error('qty')<p class="mb-3 text-sm text-red-600" data-qty-error>{{ $message }}</p>@enderror

    <x-ui.card padding="p-0">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="{{ $th }} text-left">{{ __('Item') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Suggested') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Order') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Estimated') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Min') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Per day') }}</th>
                        <th class="{{ $th }}"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($items as $line)
                        <tr wire:key="line-{{ $line->id }}">
                            <td class="px-3 py-3 text-sm font-medium text-slate-900">{{ $line->item?->name }}@if($line->added_manually)<x-ui.badge class="ml-1">{{ __('Added manually') }}</x-ui.badge>@endif</td>
                            <td class="px-3 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($line->suggested_qty) }}</td>
                            <td class="px-3 py-3 text-right text-sm font-bold tabular-nums text-brand-700">
                                @if($canEdit)
                                    <input type="number" min="0" step="any" value="{{ $fmt($line->qty) }}" wire:change="saveQty({{ $line->item_id }}, $event.target.value)"
                                           class="w-24 rounded-lg border border-slate-300 px-2 py-1 text-right text-sm focus:border-brand-600 focus:ring-brand-600" />
                                @else
                                    {{ $fmt($line->qty) }}
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($line->est) }}</td>
                            <td class="px-3 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($line->min) }}</td>
                            <td class="px-3 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($line->speed) }}</td>
                            <td class="px-3 py-3 text-right text-sm">
                                @if($canEdit)<button type="button" wire:click="removeItem({{ $line->item_id }})" class="text-red-600 hover:underline">{{ __('Remove') }}</button>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-sm text-slate-500">{{ __('No items in this order.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if($canEdit)
        <x-ui.card class="mt-4">
            <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Add an item') }}</h3>
            <div class="flex flex-wrap items-end gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search items') }}"
                       class="w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-600 focus:ring-brand-600" />
                <input type="number" min="0" step="any" wire:model="addQty" aria-label="{{ __('Order') }}"
                       class="w-24 rounded-lg border border-slate-300 px-2 py-2 text-right text-sm focus:border-brand-600 focus:ring-brand-600" />
            </div>
            @if($results->isNotEmpty())
                <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach($results as $found)
                        <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="found-{{ $found->id }}">
                            <span>{{ $found->name }}</span>
                            <x-ui.button type="button" variant="secondary" wire:click="addItem({{ $found->id }})">{{ __('Add') }}</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    @if($canShare)
        <x-ui.card class="mt-4" data-share>
            <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Links for the shop managers') }}</h3>
            <p class="mb-2 text-xs text-slate-500">{{ __('Every responsible user has his own link; what is changed through it is recorded for that user. It stops working as soon as the order is confirmed.') }}</p>
            @if($responsible->isEmpty())
                <p class="text-sm text-amber-700" data-no-responsible>{{ __('No active responsible user is assigned to this shop.') }}</p>
            @else
                <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false }"
                     x-on:order-link-ready.window="navigator.clipboard?.writeText($event.detail.url).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})">
                    <select wire:model="linkUserId" aria-label="{{ __('Responsible user') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-600 focus:ring-brand-600">
                        <option value="">{{ __('Choose a user') }}</option>
                        @foreach($responsible as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="button" variant="secondary" wire:click="makeLink">
                        <span x-show="! copied">{{ __('Copy link') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="sendTelegram" wire:loading.attr="disabled" wire:target="sendTelegram">{{ __('Send link to Telegram again') }}</x-ui.button>
                </div>
                @error('linkUserId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                @if($generatedLink !== '')
                    <input type="text" readonly value="{{ $generatedLink }}" data-generated-link class="mt-2 w-full rounded-lg border border-slate-300 bg-sand-50 px-3 py-2 text-xs text-slate-600" onfocus="this.select()" />
                @endif
            @endif
            @if($links !== [])
                <ul class="mt-3 space-y-2" data-order-links>
                    @foreach($links as $link)
                        <li class="flex flex-wrap items-center gap-2" x-data="{ copied: false }" wire:key="plink-{{ $loop->index }}">
                            <span class="w-40 shrink-0 truncate text-sm text-slate-700">{{ $link['name'] }}</span>
                            <input type="text" readonly value="{{ $link['url'] }}" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-sand-50 px-3 py-2 text-xs text-slate-600" onfocus="this.select()" />
                            <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700"
                                    @click="navigator.clipboard?.writeText(@js($link['url'])); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-2 text-xs text-slate-500">{{ __('Telegram') }}: @include('livewire.orders.partials.telegram-badge', ['order' => $order])</p>
            @if($telegramNote !== '')
                <p class="mt-2 text-sm {{ $telegramStatus === 'sent' ? 'text-brand-700' : 'text-amber-700' }}" data-telegram-result>{{ $telegramNote }}</p>
            @endif
        </x-ui.card>
    @elseif($order->status === \App\Enums\OrderStatus::Draft)
        @if($links !== [])
            <x-ui.card class="mt-4" data-my-link>
                <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Your link for the phone') }}</h3>
                <p class="mb-2 text-xs text-slate-500">{{ __('Open this link on your phone: a simple page with the items, quantities, a comment and the Accept button. It stops working when the order is confirmed.') }}</p>
                <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                    <input type="text" readonly value="{{ $links[0]['url'] }}" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-sand-50 px-3 py-2 text-xs text-slate-600" onfocus="this.select()" />
                    <a href="{{ $links[0]['url'] }}" target="_blank" rel="noopener noreferrer" class="shrink-0 rounded-md bg-brand-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600">{{ __('Open') }}</a>
                    <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700"
                            @click="navigator.clipboard?.writeText(@js($links[0]['url'])); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                </div>
            </x-ui.card>
        @endif
        <p class="mt-4 text-xs text-slate-500">{{ __('Telegram') }}: @include('livewire.orders.partials.telegram-badge', ['order' => $order])</p>
    @endif

    {{-- Comment of the responsible person: editable while a draft, shown when set afterwards --}}
    @if($canEdit)
        <x-ui.card class="mt-4">
            <label for="order-comment" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Comment') }}</label>
            <textarea id="order-comment" rows="3" maxlength="1000" wire:model="comment" wire:blur="saveComment" placeholder="{{ __('A note for the warehouse (optional)') }}"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-600 focus:ring-brand-600"></textarea>
            @error('comment')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </x-ui.card>
    @elseif((string) $order->comment !== '')
        <x-ui.card class="mt-4" data-comment>
            <h3 class="mb-1 text-sm font-semibold text-slate-700">{{ __('Comment') }}</h3>
            <p class="whitespace-pre-line text-sm text-slate-700">{{ $order->comment }}</p>
        </x-ui.card>
    @endif

    <div class="mt-4 flex flex-wrap gap-3">
        @if($canConfirm)
            <x-ui.button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                         wire:confirm="{{ __('Confirm this order and send it to 1C? It cannot be edited afterwards.') }}">{{ __('Confirm order') }}</x-ui.button>
        @endif
        @if($canRetry)
            <x-ui.button type="button" variant="secondary" wire:click="retrySync">{{ __('Retry sync') }}</x-ui.button>
        @endif
        @if($canDelete)
            <x-ui.button type="button" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this draft?') }}">{{ __('Delete draft') }}</x-ui.button>
        @endif
    </div>

    @include('livewire.orders.partials.history', ['events' => $events, 'meId' => auth()->id()])
</div>
