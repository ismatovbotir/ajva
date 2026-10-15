{{-- Global confirmation dialog. Rendered once per layout, outside any Livewire component. resources/js/confirm-modal.js
     intercepts clicks on elements carrying wire:confirm and fills + opens this markup. Static on purpose (no Livewire
     state), so Livewire morphs never touch it. Optional attributes on the trigger: data-confirm-title, data-confirm-tone="danger|primary". --}}
<div id="confirm-modal" class="fixed inset-0 z-[80] hidden items-end justify-center px-4 py-4 sm:items-center sm:py-6" data-default-title="{{ __('Please confirm') }}" data-cancel-label="{{ __('Cancel') }}" data-confirm-label="{{ __('Confirm') }}">
    <div data-confirm-backdrop class="fixed inset-0 bg-slate-900/50 opacity-0 transition-opacity duration-150 motion-reduce:transition-none"></div>
    <div
        data-confirm-panel
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-modal-title"
        aria-describedby="confirm-modal-message"
        class="relative w-full max-w-md translate-y-4 rounded-xl bg-white p-6 text-center opacity-0 shadow-xl transition duration-150 motion-reduce:transition-none sm:text-left"
    >
        <div class="sm:flex sm:items-start sm:gap-4">
            <span data-confirm-icon-wrap class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 sm:mx-0">
                <span data-confirm-icon-danger class="hidden"><x-icon name="exclamation" class="h-6 w-6" /></span>
                <span data-confirm-icon-primary><x-icon name="check" class="h-6 w-6" /></span>
            </span>
            <div class="mt-4 min-w-0 sm:mt-0">
                <h2 id="confirm-modal-title" data-confirm-title class="text-lg font-semibold text-slate-900"></h2>
                <p id="confirm-modal-message" data-confirm-message class="mt-2 whitespace-pre-line break-words text-sm text-slate-600"></p>
            </div>
        </div>
        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <button type="button" data-confirm-cancel class="inline-flex h-12 w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-5 text-base font-medium text-slate-700 transition hover:bg-sand-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 sm:w-auto sm:min-w-28">{{ __('Cancel') }}</button>
            <button type="button" data-confirm-ok class="inline-flex h-12 w-full items-center justify-center rounded-lg bg-brand-700 px-5 text-base font-medium text-white transition hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 sm:w-auto sm:min-w-28">{{ __('Confirm') }}</button>
        </div>
    </div>
</div>
