@props(['show' => false, 'title' => null, 'close' => 'closeModal'])
@if($show)
    <div class="fixed inset-0 z-50 flex items-end justify-center px-4 py-6 sm:items-center" wire:key="modal">
        <div class="fixed inset-0 bg-slate-900/50" wire:click="{{ $close }}"></div>
        <div {{ $attributes->merge(['class' => 'relative w-full max-w-lg rounded-xl bg-surface p-6 shadow-xl max-h-[90vh] overflow-y-auto']) }}>
            <div class="mb-4 flex items-center justify-between">
                @if($title)
                    <h2 class="text-lg font-semibold text-slate-900">{{ $title }}</h2>
                @endif
                <button type="button" wire:click="{{ $close }}" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                    <x-icon name="close" class="h-5 w-5" />
                    <span class="sr-only">{{ __('Close') }}</span>
                </button>
            </div>
            {{ $slot }}
        </div>
    </div>
@endif
