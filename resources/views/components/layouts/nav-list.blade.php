@props(['groups', 'collapsible' => false])
{{-- Groups start COLLAPSED and fold by clicking their heading; the open ones are kept per browser (localStorage
     `navOpen`). The group holding the current page is always open, a `single` group (Home) is just a link without a
     heading, and in the icon-only sidebar every group is shown. --}}
<div
    x-data="{
        open: (() => { try { const v = JSON.parse(localStorage.getItem('navOpen') || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } })(),
        isOpen(key) { return this.open.includes(key); },
        toggle(key) {
            this.open = this.isOpen(key) ? this.open.filter(k => k !== key) : [...this.open, key];
            try { localStorage.setItem('navOpen', JSON.stringify(this.open)); } catch (e) {}
        },
    }"
>
@foreach ($groups as $group)
    @php
        $key = $group['label'];
        $hasActive = collect($group['items'])->contains(fn ($i) => request()->routeIs($i['route']));
    @endphp
    <div @class(['mt-5' => ! $loop->first]) role="group" aria-label="{{ __($group['label']) }}"
         @if ($hasActive) x-init="if (! isOpen(@js($key))) open = [...open, @js($key)]" @endif>
        @if (! empty($group['single']))
            @foreach ($group['items'] as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a
                    href="{{ route($item['route']) }}"
                    @if ($collapsible) :title="sidebarCollapsed ? @js(__($item['label'])) : null" @endif
                    @if ($active) aria-current="page" @endif
                    class="flex items-center gap-3 rounded-md px-2.5 py-1.5 text-sm font-medium {{ $active ? 'bg-primary-50 text-primary-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}"
                >
                    <x-icon :name="$item['icon']" class="h-5 w-5 flex-shrink-0 {{ $active ? 'text-primary-600' : 'text-slate-400' }}" />
                    <span @if ($collapsible) x-show="!sidebarCollapsed" x-cloak @endif class="truncate">{{ __($item['label']) }}</span>
                </a>
            @endforeach
        @else
        <button
            type="button"
            @click="toggle(@js($key))"
            :aria-expanded="isOpen(@js($key)) ? 'true' : 'false'"
            @if ($collapsible) x-show="!sidebarCollapsed" x-cloak @endif
            class="mb-1 flex w-full items-center justify-between rounded-md px-3 py-1 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-400 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
        >
            <span>{{ __($group['label']) }}</span>
            <svg class="h-3.5 w-3.5 shrink-0 transition-transform motion-reduce:transition-none" :class="isOpen(@js($key)) ? '' : '-rotate-90'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </button>
        @if ($collapsible && ! $loop->first)
            <div x-show="sidebarCollapsed" x-cloak class="mx-3 mb-2 border-t border-slate-200"></div>
        @endif
        <div class="space-y-0.5" @if ($collapsible) x-show="sidebarCollapsed || isOpen(@js($key))" @else x-show="isOpen(@js($key))" @endif x-collapse.duration.150ms>
            @foreach ($group['items'] as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a
                    href="{{ route($item['route']) }}"
                    @if ($collapsible) :title="sidebarCollapsed ? @js(__($item['label'])) : null" @endif
                    @if ($active) aria-current="page" @endif
                    class="flex items-center gap-3 rounded-md px-2.5 py-1.5 text-sm font-medium {{ $active ? 'bg-primary-50 text-primary-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}"
                >
                    <x-icon :name="$item['icon']" class="h-5 w-5 flex-shrink-0 {{ $active ? 'text-primary-600' : 'text-slate-400' }}" />
                    <span @if ($collapsible) x-show="!sidebarCollapsed" x-cloak @endif class="truncate">{{ __($item['label']) }}</span>
                </a>
            @endforeach
        </div>
        @endif
    </div>
@endforeach
</div>
