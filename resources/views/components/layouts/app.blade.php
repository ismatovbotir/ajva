@props(['title' => null])
@php
    // Single source of truth for navigation. Items whose route is not
    // registered (yet) are dropped, so a group never links to a missing route,
    // and items are hidden from roles that cannot open them (default: admin +
    // operator; the Settings group is admin-only; the monitor is open to all).
    $currentRole = auth()->user()?->role?->value;
    $navGroups = collect([
        ['label' => 'Dashboard', 'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
            ['label' => 'Receipts', 'route' => 'receipts.index', 'icon' => 'receipt'],
            ['label' => 'Analytics', 'route' => 'analytics.index', 'icon' => 'chart'],
            ['label' => 'Monitor', 'route' => 'monitor', 'icon' => 'monitor', 'roles' => ['admin', 'operator', 'monitor']],
        ]],
        ['label' => 'Entities', 'items' => [
            ['label' => 'Shops', 'route' => 'shops.index', 'icon' => 'shop'],
            ['label' => 'Pos', 'route' => 'pos.index', 'icon' => 'pos'],
            ['label' => 'Items', 'route' => 'items.index', 'icon' => 'box'],
            ['label' => 'Groups', 'route' => 'groups.index', 'icon' => 'group'],
            ['label' => 'Categories', 'route' => 'categories.index', 'icon' => 'category'],
            ['label' => 'Prices', 'route' => 'prices.index', 'icon' => 'currency'],
        ]],
        ['label' => 'Settings', 'roles' => ['admin'], 'items' => [
            ['label' => 'MCP server', 'route' => 'settings.mcp', 'icon' => 'adjustments'],
            ['label' => 'Public monitor', 'route' => 'settings.monitor', 'icon' => 'monitor'],
            ['label' => 'Users', 'route' => 'users.index', 'icon' => 'users'],
        ]],
    ])->map(function ($group) use ($currentRole) {
        $groupRoles = $group['roles'] ?? ['admin', 'operator'];
        $group['items'] = array_values(array_filter(
            $group['items'],
            fn ($i) => Route::has($i['route'])
                && in_array($currentRole, $i['roles'] ?? $groupRoles, true)
        ));

        return $group;
    })->filter(fn ($g) => count($g['items']) > 0)->values()->all();

    // Primary mobile destinations; everything else lives behind "Menu".
    $primaryRoutes = ['dashboard', 'receipts.index', 'analytics.index', 'items.index'];
    $allNavItems = collect($navGroups)->pluck('items')->flatten(1);
    $bottomNavItems = $allNavItems
        ->filter(fn ($i) => in_array($i['route'], $primaryRoutes, true))
        ->sortBy(fn ($i) => array_search($i['route'], $primaryRoutes, true))->values();
    $menuActive = $allNavItems->contains(fn ($i) => request()->routeIs($i['route'].'*'))
        && ! $bottomNavItems->contains(fn ($i) => request()->routeIs($i['route'].'*'));
    $pageTitle = $title ? $title.' - '.config('app.name') : config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <x-theme-script />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-sand-50 text-slate-900 antialiased">
    <div
        x-data="{
            mobileMenuOpen: false,
            sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === 'true',
        }"
        x-init="$watch('sidebarCollapsed', value => localStorage.setItem('sidebarCollapsed', value))"
        class="min-h-screen md:flex"
    >
        {{-- Desktop sidebar --}}
        <aside
            :class="sidebarCollapsed ? 'md:w-20' : 'md:w-64'"
            class="hidden md:flex md:flex-shrink-0 md:flex-col md:border-r md:border-slate-200 md:bg-sidebar md:transition-all md:duration-200"
        >
            <div class="flex h-16 items-center justify-between gap-2 border-b border-slate-200 px-4">
                <span x-show="!sidebarCollapsed" x-cloak class="flex min-w-0 items-center gap-2.5"><x-ui.logo-mark class="h-8 w-8 text-base" /><span class="truncate text-lg font-semibold text-slate-900">{{ config('app.name') }}</span></span>
                <button
                    type="button"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                    class="ml-auto rounded-lg p-2 text-slate-500 hover:bg-slate-200/60 hover:text-slate-900"
                    :title="sidebarCollapsed ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'"
                >
                    <x-icon name="chevron-left" class="h-5 w-5 transition-transform duration-200" x-bind:class="sidebarCollapsed && 'rotate-180'" />
                    <span class="sr-only">{{ __('Toggle sidebar') }}</span>
                </button>
            </div>
            <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4" aria-label="{{ __('Main navigation') }}">
                <x-layouts.nav-list :groups="$navGroups" collapsible />
            </nav>
            <div class="space-y-1 border-t border-slate-200 p-3">
                <x-ui.theme-toggle label="sidebar" collapsible class="w-full px-3" />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        :title="sidebarCollapsed ? '{{ __('Log out') }}' : null"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200/60 hover:text-slate-900"
                    >
                        <x-icon name="logout" class="h-5 w-5 flex-shrink-0" />
                        <span x-show="!sidebarCollapsed" x-cloak class="truncate">{{ __('Log out') }}</span>
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex flex-1 flex-col">
            {{-- Mobile top bar --}}
            <header class="flex items-center justify-between border-b border-slate-200 bg-sidebar px-4 py-2.5 md:hidden">
                <span class="flex items-center gap-2.5"><x-ui.logo-mark class="h-8 w-8 text-base" /><span class="text-lg font-semibold text-slate-900">{{ config('app.name') }}</span></span>
                <span class="flex items-center gap-1">
                <x-ui.theme-toggle />
                <button type="button" @click="mobileMenuOpen = true" class="rounded-lg p-2 text-slate-500 hover:bg-slate-200/60 hover:text-slate-900">
                    <x-icon name="menu" class="h-6 w-6" />
                    <span class="sr-only">{{ __('Open menu') }}</span>
                </button>
                </span>
            </header>

            {{-- Desktop top bar --}}
            <header class="hidden items-center justify-between border-b border-slate-200 bg-surface px-8 py-4 md:flex">
                <h2 class="text-lg font-semibold text-slate-900">{{ $title ?? __('Dashboard') }}</h2>
            </header>

            <main class="flex-1 bg-sand-50 px-4 py-6 pb-24 md:px-8 md:py-8 md:pb-8">
                {{ $slot }}
            </main>

            {{-- Mobile bottom tab bar --}}
            <nav class="fixed inset-x-0 bottom-0 z-30 flex items-center border-t border-slate-200 bg-sidebar py-2 md:hidden">
                @foreach ($bottomNavItems as $item)
                    @php $active = request()->routeIs($item['route'].'*'); @endphp
                    <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif class="flex min-w-0 flex-1 flex-col items-center gap-1 px-1 py-1 text-[11px] font-medium {{ $active ? 'text-accent' : 'text-slate-500' }}">
                        <x-icon :name="$item['icon']" class="h-5 w-5" />
                        <span class="truncate">{{ __($item['label']) }}</span>
                    </a>
                @endforeach
                <button type="button" @click="mobileMenuOpen = true" class="flex min-w-0 flex-1 flex-col items-center gap-1 px-1 py-1 text-[11px] font-medium {{ $menuActive ? 'text-accent' : 'text-slate-500' }}">
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
            <div class="absolute inset-0 bg-slate-900/50" @click="mobileMenuOpen = false"></div>
            <div class="absolute inset-y-0 right-0 flex w-72 max-w-[85%] flex-col bg-sidebar shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <span class="flex items-center gap-2.5"><x-ui.logo-mark class="h-8 w-8 text-base" /><span class="text-lg font-semibold text-slate-900">{{ config('app.name') }}</span></span>
                    <button type="button" @click="mobileMenuOpen = false" class="rounded-lg p-2 text-slate-500 hover:bg-slate-200/60 hover:text-slate-900">
                        <x-icon name="close" class="h-6 w-6" />
                        <span class="sr-only">{{ __('Close menu') }}</span>
                    </button>
                </div>
                <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="{{ __('Main navigation') }}">
                    <x-layouts.nav-list :groups="$navGroups" />
                </nav>
                <div class="space-y-1 border-t border-slate-200 p-3">
                    <x-ui.theme-toggle label="drawer" class="w-full px-3" />
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200/60 hover:text-slate-900">
                            <x-icon name="logout" class="h-5 w-5" />
                            {{ __('Log out') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @livewireScripts
</body>
</html>
