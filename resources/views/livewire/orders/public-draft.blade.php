{{-- The phone page of a personal order link: a fixed top bar (order, shop, Accept), the list of items with an editable
     quantity, then an add-item box, the history and, last, the comment. No side menu, no cost / profit data. --}}
@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
@endphp
<div>
    @if($done)
        <div class="px-4 pt-10">
            <x-ui.card>
                <h1 class="text-lg font-semibold text-slate-900">{{ __('Order confirmed') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('Thank you. The order was sent for processing and this link no longer works.') }}</p>
            </x-ui.card>
        </div>
    @else
        {{-- Fixed top bar --}}
        <header class="fixed inset-x-0 top-0 z-30 bg-brand-700 text-white shadow-md" style="padding-top: env(safe-area-inset-top);">
            <div class="mx-auto flex max-w-xl items-center gap-3 px-3 py-2">
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="truncate text-base font-semibold" data-order-title>{{ $order->number() }}</p>
                    <p class="truncate text-xs text-brand-100">{{ $order->shop?->name }} · {{ __(':count lines', ['count' => $items->count()]) }}</p>
                </div>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" data-accept
                        wire:confirm="{{ __('Confirm this order and send it to 1C? It cannot be edited afterwards.') }}"
                        class="shrink-0 rounded-xl bg-white px-5 py-2.5 text-base font-bold text-brand-800 shadow active:bg-brand-50 disabled:opacity-60">
                    {{ __('Accept') }}
                </button>
            </div>
        </header>

        <div class="px-3 pb-10" style="padding-top: calc(4.5rem + env(safe-area-inset-top));">
            <p class="mb-2 text-xs text-slate-400" data-link-user>{{ __('Signed in by link as :name. Your changes are recorded under your name.', ['name' => $userName]) }}</p>
            <p class="mb-3 text-sm text-slate-600">{{ __('Check the quantities, change what is needed, add a comment at the bottom, then press Accept. After that the order is locked and this link stops working.') }}</p>
            @error('qty')<p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-qty-error>{{ $message }}</p>@enderror
            @error('confirm')<p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>@enderror

            {{-- Add an item: search by code, GTIN or name; new lines appear at the top of the list --}}
            @include('livewire.orders.partials.item-search', ['phone' => true])

            {{-- Items --}}
            <ul class="space-y-2" data-lines>
                @forelse($items as $line)
                    <li wire:key="line-{{ $line->id }}" class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.95rem] font-medium leading-snug text-slate-900">{{ $line->item?->name }}@include('livewire.orders.partials.line-source', ['line' => $line])</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ __('Suggested') }} {{ $fmt($line->suggested_qty) }} · {{ __('Estimated') }} {{ $fmt($line->est) }} · {{ __('Min') }} {{ $fmt($line->min) }}</p>
                            </div>
                            <input type="number" inputmode="decimal" min="0" step="any" value="{{ $fmt($line->qty) }}" aria-label="{{ __('Order') }}: {{ $line->item?->name }}"
                                   wire:change="saveQty({{ $line->item_id }}, $event.target.value)" onfocus="this.select()"
                                   class="h-12 w-24 shrink-0 rounded-xl border border-slate-300 px-2 text-right text-lg font-semibold tabular-nums focus:border-brand-600 focus:ring-brand-600" />
                            <button type="button" wire:click="removeItem({{ $line->item_id }})" aria-label="{{ __('Remove') }}" title="{{ __('Remove') }}"
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xl leading-none text-red-600 active:bg-red-50">&times;</button>
                        </div>
                    </li>
                @empty
                    <li class="rounded-xl border border-slate-200 bg-white px-3 py-6 text-center text-sm text-slate-500">{{ __('No items in this order.') }}</li>
                @endforelse
            </ul>

            {{-- Changes (collapsed) --}}
            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-semibold text-slate-600">{{ __('Changes') }}</summary>
                @include('livewire.orders.partials.history', ['events' => $events, 'meId' => $meId])
            </details>

            {{-- The comment is the LAST field --}}
            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <label for="order-comment" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Comment') }}</label>
                <textarea id="order-comment" rows="4" maxlength="1000" wire:model="comment" wire:blur="saveComment" placeholder="{{ __('A note for the warehouse (optional)') }}"
                          class="w-full rounded-xl border border-slate-300 px-3 py-2 text-base focus:border-brand-600 focus:ring-brand-600"></textarea>
                @error('comment')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
    @endif
</div>
