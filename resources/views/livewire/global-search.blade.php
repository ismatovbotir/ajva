<div
    x-data="{
        open: false,
        openIt() { this.open = true; this.$nextTick(() => this.$refs.input.focus()); },
        close() { this.open = false; this.$wire.query = ''; },
        links() { return Array.from(this.$refs.panel.querySelectorAll('[data-search-result]')); },
        move(step) {
            const list = this.links();
            if (! list.length) return;
            const at = list.indexOf(document.activeElement);
            const next = at === -1 ? (step > 0 ? 0 : list.length - 1) : at + step;
            if (next < 0) { this.$refs.input.focus(); return; }
            list[Math.min(next, list.length - 1)].focus();
        },
    }"
    @open-global-search.window="openIt()"
    @keydown.window.ctrl.k.prevent="openIt()"
    @keydown.window.meta.k.prevent="openIt()"
    @keydown.window="if ($event.key === '/' && ! $event.ctrlKey && ! $event.metaKey && ! open && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable) { $event.preventDefault(); openIt(); }"
    @keydown.escape.window="if (open) close()"
>
    <div x-show="open" x-cloak style="display: none;" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="{{ __('Search') }}" data-global-search>
        <div class="absolute inset-0 bg-slate-900/40" @click="close()"></div>
        <div x-ref="panel" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" class="relative mx-auto mt-4 flex max-h-[85vh] w-[calc(100%-1.5rem)] max-w-xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl md:mt-20">
            <div class="flex items-center gap-2 border-b border-slate-200 px-3 py-2">
                <x-icon name="search" class="h-5 w-5 shrink-0 text-slate-400" />
                <input
                    x-ref="input"
                    type="search"
                    wire:model.live.debounce.300ms="query"
                    @keydown.enter.prevent="$refs.panel.querySelector('[data-search-result]')?.click()"
                    placeholder="{{ __('Search items, orders, receipts, shops, pages') }}"
                    autocomplete="off"
                    class="min-w-0 flex-1 border-0 bg-transparent py-1.5 text-base text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                    aria-label="{{ __('Search') }}"
                >
                <span wire:loading wire:target="query" style="display: none;" class="text-xs text-slate-500">{{ __('Searching...') }}</span>
                <button type="button" @click="close()" class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                    <x-icon name="close" class="h-5 w-5" />
                    <span class="sr-only">{{ __('Close') }}</span>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                @if (! $hasQuery)
                    <p class="px-4 py-6 text-sm text-slate-500" data-search-hint>{{ __('Type at least :count characters.', ['count' => $minChars]) }}</p>
                @elseif ($groups === [])
                    <p class="px-4 py-6 text-sm text-slate-500" data-search-empty>{{ __('Nothing found.') }}</p>
                @else
                    @foreach ($groups as $group)
                        <div data-search-group="{{ $group['key'] }}">
                            <p class="bg-slate-50 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $group['label'] }}</p>
                            <ul>
                                @foreach ($group['rows'] as $row)
                                    <li>
                                        <a href="{{ $row['url'] }}" @if(str_contains((string) $row['url'], '/receipts/')) target="_blank" rel="noopener" @endif data-search-result class="flex items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-primary-50 focus:bg-primary-50 focus:outline-none">
                                            <span class="min-w-0 truncate font-medium text-slate-900">{{ $row['title'] }}</span>
                                            @if ($row['sub'] !== '')
                                                <span class="shrink-0 truncate text-xs text-slate-500">{{ $row['sub'] }}</span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="hidden items-center gap-3 border-t border-slate-200 bg-slate-50 px-4 py-1.5 text-[11px] text-slate-500 md:flex">
                <span><kbd class="rounded border border-slate-200 bg-white px-1 font-sans">↑</kbd> <kbd class="rounded border border-slate-200 bg-white px-1 font-sans">↓</kbd> {{ __('to move') }}</span>
                <span><kbd class="rounded border border-slate-200 bg-white px-1 font-sans">Enter</kbd> {{ __('to open') }}</span>
                <span><kbd class="rounded border border-slate-200 bg-white px-1 font-sans">Esc</kbd> {{ __('to close') }}</span>
            </div>
        </div>
    </div>
</div>
