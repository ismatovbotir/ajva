@props(['show' => false, 'title' => null, 'close' => 'closeModal'])
{{-- Footer convention: a direct child of the form with classes "flex justify-end gap-2" (Cancel + Save). Below sm its
     buttons stack full width, 48 px tall, primary first; from sm up they are the normal right-aligned row. --}}
@php
    $footer = '[&_form>div.justify-end]:max-sm:flex-col-reverse [&_form>div.justify-end>*]:max-sm:h-12 [&_form>div.justify-end>*]:max-sm:w-full [&_form>div.justify-end>*]:max-sm:text-base';
@endphp
@if($show)
    <div class="fixed inset-0 z-50 flex items-end justify-center px-4 py-6 sm:items-center" wire:key="modal">
        <div class="fixed inset-0 bg-slate-900/50" wire:click="{{ $close }}"></div>
        <div {{ $attributes->merge(['class' => 'relative w-full max-w-lg rounded-xl bg-white p-6 shadow-xl max-h-[90vh] overflow-y-auto '.$footer]) }}>
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
