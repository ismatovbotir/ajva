@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
@endphp
<div>
    @if($done)
        <x-ui.card>
            <h1 class="text-lg font-semibold text-slate-900">{{ __('Order confirmed') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('Thank you. The order was sent for processing and this link no longer works.') }}</p>
        </x-ui.card>
    @else
        <x-ui.page-header :title="$order->number().' · '.($order->shop?->name)" :subtitle="__('Order to the main warehouse')" />

        <p class="mb-1 text-xs text-slate-400" data-link-user>{{ __('Signed in by link as :name. Your changes are recorded under your name.', ['name' => $userName]) }}</p>
        <p class="mb-3 text-sm text-slate-500">{{ __('This order is a draft: change the quantities, remove lines or add items, then confirm. After confirming it is locked and this link stops working.') }}</p>
        @error('qty')<p class="mb-3 text-sm text-red-600" data-qty-error>{{ $message }}</p>@enderror

        <div class="space-y-2" data-lines>
            @forelse($items as $line)
                <x-ui.card padding="p-3" wire:key="line-{{ $line->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $line->item?->name }}@if($line->added_manually)<x-ui.badge class="ml-1">{{ __('Added manually') }}</x-ui.badge>@endif</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ __('Suggested') }}: {{ $fmt($line->suggested_qty) }} · {{ __('Estimated') }}: {{ $fmt($line->est) }} · {{ __('Min') }}: {{ $fmt($line->min) }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <input type="number" min="0" step="any" value="{{ $fmt($line->qty) }}" aria-label="{{ __('Order') }}" wire:change="saveQty({{ $line->item_id }}, $event.target.value)"
                                   class="w-24 rounded-lg border border-slate-300 px-2 py-1 text-right text-sm focus:border-brand-600 focus:ring-brand-600" />
                            <button type="button" wire:click="removeItem({{ $line->item_id }})" class="text-sm text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </div>
                    </div>
                </x-ui.card>
            @empty
                <p class="rounded-lg border border-slate-200 bg-white px-3 py-6 text-center text-sm text-slate-500">{{ __('No items in this order.') }}</p>
            @endforelse
        </div>

        <x-ui.card class="mt-4">
            <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Add an item') }}</h3>
            <div class="flex flex-wrap items-end gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search items') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-600 focus:ring-brand-600 sm:w-64" />
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

        @error('confirm')<p class="mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4">
            <x-ui.button type="button" class="w-full sm:w-auto" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                         wire:confirm="{{ __('Confirm this order and send it to 1C? It cannot be edited afterwards.') }}">{{ __('Confirm order') }}</x-ui.button>
        </div>

        @include('livewire.orders.partials.history', ['events' => $events, 'meId' => $meId])
    @endif
</div>
