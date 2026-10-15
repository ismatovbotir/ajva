@props(['groups', 'collapsible' => false])
{{-- Groups fold by clicking their heading; the choice is kept per browser. The group holding the current page
     always starts open, and in the icon-only sidebar every group is shown. --}}
<div
    x-data="{
        closed: (() => { try { return JSON.parse(localStorage.getItem('navClosed') || '[]'); } catch (e) { return []; } })(),
        isOpen(key) { return ! this.closed.includes(key); },
        toggle(key) {
            this.closed = this.isOpen(key) ? [...this.closed, key] : this.closed.filter(k => k !== key);
            try { localStorage.setItem('navClosed', JSON.stringify(this.closed)); } catch (e) {}
        },
    }"
>
@foreach ($groups as $group)
    @php
        $key = $group['label'];
        $hasActive = collect($group['items'])->contains(fn ($i) => request()->routeIs($i['route']));
    @endphp
    <div @class(['mt-5' => ! $loop->first]) role="group" aria-label="{{ __($group['label']) }}"
         @if ($hasActive) x-init="closed = closed.filter(k => k !== @js($key))" @endif>
        <button
            type="button"
            @click="toggle(@js($key))"
            :aria-expanded="isOpen(@js($key)) ? 'true' : 'false'"
            @if ($collapsible) x-show="!sidebarCollapsed" x-cloak @endif
            class="mb-1 flex w-full items-center justify-between rounded-md px-3 py-1 text-left text-[11px] font-semibold uppercase tracking-wider text-brand-300 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-300"
        >
            <span>{{ __($group['label']) }}</span>
            <svg class="h-3.5 w-3.5 shrink-0 transition-transform motion-reduce:transition-none" :class="isOpen(@js($key)) ? '' : '-rotate-90'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </button>
        @if ($collapsible && ! $loop->first)
            <div x-show="sidebarCollapsed" x-cloak class="mx-3 mb-2 border-t border-brand-600"></div>
        @endif
        <div class="space-y-0.5" @if ($collapsible) x-show="sidebarCollapsed || isOpen(@js($key))" @else x-show="isOpen(@js($key))" @endif x-collapse.duration.150ms>
            @foreach ($group['items'] as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a
                    href="{{ route($item['route']) }}"
                    @if ($collapsible) :title="sidebarCollapsed ? @js(__($item['label'])) : null" @endif
                    @if ($active) aria-current="page" @endif
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ $active ? 'bg-brand-600 text-white shadow-[inset_3px_0_0_0_#fff]' : 'text-brand-100 hover:bg-brand-600/60 hover:text-white' }}"
                >
                    <x-icon :name="$item['icon']" class="h-5 w-5 flex-shrink-0" />
                    <span @if ($collapsible) x-show="!sidebarCollapsed" x-cloak @endif class="truncate">{{ __($item['label']) }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endforeach
</div>
