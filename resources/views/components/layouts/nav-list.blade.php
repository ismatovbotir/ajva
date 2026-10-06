@props(['groups', 'collapsible' => false])
@foreach ($groups as $group)
    <div @class(['mt-5' => ! $loop->first]) role="group" aria-label="{{ __($group['label']) }}">
        <p
            @if ($collapsible) x-show="!sidebarCollapsed" x-cloak @endif
            class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500"
        >{{ __($group['label']) }}</p>
        @if ($collapsible && ! $loop->first)
            <div x-show="sidebarCollapsed" x-cloak class="mx-3 mb-2 border-t border-slate-200"></div>
        @endif
        <div class="space-y-0.5">
            @foreach ($group['items'] as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <a
                    href="{{ route($item['route']) }}"
                    @if ($collapsible) :title="sidebarCollapsed ? @js(__($item['label'])) : null" @endif
                    @if ($active) aria-current="page" @endif
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ $active ? 'bg-surface text-accent shadow-sm ring-1 ring-slate-200 shadow-[inset_3px_0_0_0_var(--color-brand-600)]' : 'text-slate-600 hover:bg-slate-200/60 hover:text-slate-900' }}"
                >
                    <x-icon :name="$item['icon']" class="h-5 w-5 flex-shrink-0" />
                    <span @if ($collapsible) x-show="!sidebarCollapsed" x-cloak @endif class="truncate">{{ __($item['label']) }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endforeach
