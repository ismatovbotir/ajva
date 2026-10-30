{{-- Search box at the TOP of a draft: by code, GTIN or name; the dropdown opens after 3 characters, a tap adds the item. --}}
@php $phone = $phone ?? false; @endphp
<div class="relative {{ $phone ? 'mb-3' : 'mb-4' }}" data-item-search>
    <label for="item-search" class="mb-1 block text-sm font-semibold text-slate-700">{{ __('Add an item') }}</label>
    <input id="item-search" type="search" wire:model.live.debounce.300ms="search" autocomplete="off"
           placeholder="{{ __('Code, GTIN or name (at least 3 characters)') }}"
           class="{{ $phone ? 'h-12 text-base' : 'h-10 text-sm' }} block w-full rounded-xl border border-slate-300 px-3 shadow-sm focus:border-brand-600 focus:ring-brand-600" />

    @if(mb_strlen(trim($search)) >= 3)
        <ul class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg" data-search-results>
            @forelse($results as $found)
                <li wire:key="found-{{ $found->id }}" class="border-b border-slate-100 last:border-0">
                    <button type="button" wire:click="addItem({{ $found->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-brand-50 active:bg-brand-50">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-slate-900">{{ $found->name }}</span>
                            <span class="block truncate text-xs text-slate-500">
                                {{ __('Code') }} {{ $found->mark ?: '#'.$found->id }}@if($found->barcodes->isNotEmpty()) · GTIN {{ $found->barcodes->first()->gtin }}@endif
                            </span>
                        </span>
                        <span class="shrink-0 text-xs font-semibold text-brand-700">+ {{ __('Add') }}</span>
                    </button>
                </li>
            @empty
                <li class="px-3 py-3 text-sm text-slate-500" data-search-empty>{{ __('Nothing found.') }}</li>
            @endforelse
        </ul>
    @endif
</div>
