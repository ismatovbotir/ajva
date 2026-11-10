@props(['title' => null])
@php
    // The menu lives in App\Support\Navigation (groups, roles, icons); the /monitor screen has no menu item:
    // admins manage monitors in Settings > Monitors, and monitor-only accounts land on it after signing in.
    $currentRole = auth()->user()?->role?->value;
    $navGroups = \App\Support\Navigation::groups($currentRole);

    // Primary mobile destinations; everything else lives behind "Menu".
    $primaryRoutes = ['dashboard', 'receipts.index', 'analytics.index', 'items.index'];
    $allNavItems = collect($navGroups)->pluck('items')->flatten(1);
    $bottomNavItems = $allNavItems
        ->filter(fn ($i) => in_array($i['route'], $primaryRoutes, true))
        ->sortBy(fn ($i) => array_search($i['route'], $primaryRoutes, true))->values();
    $menuActive = $allNavItems->contains(fn ($i) => request()->routeIs($i['route'].'*'))
        && ! $bottomNavItems->contains(fn ($i) => request()->routeIs($i['route'].'*'));
    // Global search (Ctrl+K or /) is for the roles that work with data; a monitor-only account has nothing to search.
    $canSearch = in_array($currentRole, ['admin', 'operator'], true);
    $pageTitle = $title ? $title.' - '.config('app.name') : config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-sand-50 text-slate-900 antialiased">
    @php $authUser = auth()->user(); @endphp
    <div
        x-data="{
            mobileMenuOpen: false,
            sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === 'true',
        }"
        x-init="$watch('sidebarCollapsed', value => localStorage.setItem('sidebarCollapsed', value))"
        @keydown.window="if ($event.key === '[' && !$event.ctrlKey && !$event.metaKey && !['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName) && !$event.target.isContentEditable) sidebarCollapsed = !sidebarCollapsed"
        class="min-h-screen md:flex md:h-screen md:overflow-hidden"
    >
        {{-- Desktop sidebar --}}
        <aside
            :class="sidebarCollapsed ? 'md:w-16' : 'md:w-60'"
            class="hidden border-r border-slate-200 bg-white md:flex md:h-full md:flex-shrink-0 md:flex-col md:transition-all md:duration-200"
        >
            <div class="flex h-14 items-center gap-2.5 border-b border-slate-200 px-4">
                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-md bg-brand-700 text-sm font-semibold text-white">{{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}</span>
                <span x-show="!sidebarCollapsed" x-cloak class="truncate text-sm font-semibold tracking-tight text-slate-900">{{ config('app.name') }}</span>
            </div>
            <nav class="flex-1 overflow-y-auto overflow-x-hidden px-2.5 py-3" aria-label="{{ __('Main navigation') }}">
                <x-layouts.nav-list :groups="$navGroups" collapsible />
            </nav>
            <div class="space-y-0.5 border-t border-slate-200 p-2.5">
                <button
                    type="button"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                    class="flex w-full items-center gap-3 rounded-md px-2.5 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                    :title="sidebarCollapsed ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'"
                >
                    <x-icon name="chevron-left" class="h-5 w-5 flex-shrink-0 transition-transform duration-200" x-bind:class="sidebarCollapsed && 'rotate-180'" />
                    <span x-show="!sidebarCollapsed" x-cloak class="flex flex-1 items-center justify-between truncate">{{ __('Toggle sidebar') }} <kbd class="rounded border border-slate-200 px-1 font-sans text-[10px] text-slate-400">[</kbd></span>
                </button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        :title="sidebarCollapsed ? '{{ __('Log out') }}' : null"
                        class="flex w-full items-center gap-3 rounded-md px-2.5 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                    >
                        <x-icon name="logout" class="h-5 w-5 flex-shrink-0" />
                        <span x-show="!sidebarCollapsed" x-cloak class="truncate">{{ __('Log out') }}</span>
                    </button>
                </form>
            </div>
        </aside>

        {{-- On desktop the window is exactly one screen tall: the sidebar and top bar stay put and
             only <main> scrolls. On mobile the page scrolls normally (top bar + bottom tab bar). --}}
        <div class="flex min-w-0 flex-1 flex-col md:h-full md:min-h-0">
            {{-- Mobile top bar --}}
            <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-2.5 md:hidden">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                    <span class="flex h-6 w-6 items-center justify-center rounded-md bg-brand-700 text-xs text-white">{{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}</span>
                    {{ config('app.name') }}
                </span>
                <span class="flex items-center">
                    @if ($canSearch)
                        <button type="button" @click="$dispatch('open-global-search')" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900" data-search-button>
                            <x-icon name="search" class="h-6 w-6" />
                            <span class="sr-only">{{ __('Search') }}</span>
                        </button>
                    @endif
                    <button type="button" @click="mobileMenuOpen = true" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                        <x-icon name="menu" class="h-6 w-6" />
                        <span class="sr-only">{{ __('Open menu') }}</span>
                    </button>
                </span>
            </header>

            {{-- Desktop top bar --}}
            <header class="hidden h-14 items-center justify-between border-b border-slate-200 bg-white px-8 md:flex">
                <h2 class="truncate text-sm font-semibold text-slate-900">{{ $title ?? __('Home') }}</h2>
                @if ($canSearch)
                    <button type="button" @click="$dispatch('open-global-search')" class="ml-auto mr-4 flex w-64 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-left text-sm text-slate-500 hover:border-slate-300 hover:bg-white" data-search-button>
                        <x-icon name="search" class="h-4 w-4 shrink-0" />
                        <span class="flex-1 truncate">{{ __('Search') }}</span>
                        <kbd class="rounded border border-slate-200 bg-white px-1 font-sans text-[10px] text-slate-400">Ctrl K</kbd>
                    </button>
                @endif
                @if ($authUser)
                    <div class="flex items-center gap-2.5">
                        <div class="text-right leading-tight">
                            <p class="text-sm font-medium text-slate-900">{{ $authUser->name }}</p>
                            <p class="text-xs text-slate-500">{{ $authUser->role?->label() }}</p>
                        </div>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ mb_strtoupper(mb_substr($authUser->name, 0, 1)) }}</span>
                    </div>
                @endif
            </header>

            <main class="flex-1 px-4 py-5 pb-24 md:min-h-0 md:overflow-y-auto md:px-8 md:py-6 md:pb-8">
                {{ $slot }}
            </main>

            {{-- Mobile bottom tab bar --}}
            <nav class="fixed inset-x-0 bottom-0 z-30 flex items-center border-t border-slate-200 bg-white/95 py-1.5 backdrop-blur md:hidden">
                @foreach ($bottomNavItems as $item)
                    @php $active = request()->routeIs($item['route'].'*'); @endphp
                    <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif class="flex min-w-0 flex-1 flex-col items-center gap-0.5 px-1 py-1 text-[11px] font-medium {{ $active ? 'text-primary-600' : 'text-slate-500' }}">
                        <x-icon :name="$item['icon']" class="h-5 w-5" />
                        <span class="truncate">{{ __($item['label']) }}</span>
                    </a>
                @endforeach
                <button type="button" @click="mobileMenuOpen = true" class="flex min-w-0 flex-1 flex-col items-center gap-0.5 px-1 py-1 text-[11px] font-medium {{ $menuActive ? 'text-primary-600' : 'text-slate-500' }}">
                    <x-icon name="menu" class="h-5 w-5" />
                    <span class="truncate">{{ __('Menu') }}</span>
                </button>
            </nav>
        </div>

        {{-- Mobile full nav drawer --}}
        <div
            x-show="mobileMenuOpen"
            x-cloak
            class="fixed inset-0 z-40 md:hidden"
            style="display: none;"
        >
            <div class="absolute inset-0 bg-slate-900/40" @click="mobileMenuOpen = false"></div>
            <div class="absolute inset-y-0 right-0 flex w-72 max-w-[85%] flex-col border-l border-slate-200 bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <span class="text-sm font-semibold text-slate-900">{{ config('app.name') }}</span>
                    <button type="button" @click="mobileMenuOpen = false" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                        <x-icon name="close" class="h-6 w-6" />
                        <span class="sr-only">{{ __('Close menu') }}</span>
                    </button>
                </div>
                <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="{{ __('Main navigation') }}">
                    <x-layouts.nav-list :groups="$navGroups" />
                </nav>
                <div class="border-t border-slate-200 p-3">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                            <x-icon name="logout" class="h-5 w-5" />
                            {{ __('Log out') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <x-ui.confirm-modal />
    @if ($canSearch)
        <livewire:global-search />
    @endif
    @livewireScripts
</body>
</html>
