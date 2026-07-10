@props(['title' => null])
@php
    $navItems = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
        ['label' => 'Shops', 'route' => 'shops.index', 'icon' => 'shop'],
        ['label' => 'Pos', 'route' => 'pos.index', 'icon' => 'pos'],
        ['label' => 'Groups', 'route' => 'groups.index', 'icon' => 'group'],
        ['label' => 'Categories', 'route' => 'categories.index', 'icon' => 'category'],
        ['label' => 'Prices', 'route' => 'prices.index', 'icon' => 'currency'],
        ['label' => 'Items', 'route' => 'items.index', 'icon' => 'box'],
        ['label' => 'Receipts', 'route' => 'receipts.index', 'icon' => 'receipt'],
    ];

    $bottomNavItems = collect($navItems)->take(4);
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
    <div x-data="{ mobileMenuOpen: false }" class="min-h-screen md:flex">
        {{-- Desktop sidebar --}}
        <aside class="hidden md:flex md:w-64 md:flex-shrink-0 md:flex-col md:bg-brand-700">
            <div class="flex h-16 items-center border-b border-brand-600 px-6">
                <span class="text-lg font-semibold text-white">{{ config('app.name') }}</span>
            </div>
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                @foreach ($navItems as $item)
                    @php $active = Route::has($item['route']) && request()->routeIs($item['route']); @endphp
                    @if (Route::has($item['route']))
                        <a
                            href="{{ route($item['route']) }}"
                            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ $active ? 'bg-brand-600 text-white' : 'text-brand-100 hover:bg-brand-600 hover:text-white' }}"
                        >
                            <x-icon :name="$item['icon']" class="h-5 w-5" />
                            {{ __($item['label']) }}
                        </a>
                    @else
                        <span class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-brand-400">
                            <x-icon :name="$item['icon']" class="h-5 w-5" />
                            {{ __($item['label']) }}
                        </span>
                    @endif
                @endforeach
            </nav>
            <div class="border-t border-brand-600 p-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-brand-100 hover:bg-brand-600 hover:text-white">
                        <x-icon name="logout" class="h-5 w-5" />
                        {{ __('Log out') }}
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex flex-1 flex-col">
            {{-- Mobile top bar --}}
            <header class="flex items-center justify-between bg-brand-700 px-4 py-3 md:hidden">
                <span class="text-lg font-semibold text-white">{{ config('app.name') }}</span>
                <button type="button" @click="mobileMenuOpen = true" class="rounded-lg p-2 text-brand-100 hover:bg-brand-600 hover:text-white">
                    <x-icon name="menu" class="h-6 w-6" />
                    <span class="sr-only">{{ __('Open menu') }}</span>
                </button>
            </header>

            {{-- Desktop top bar --}}
            <header class="hidden items-center justify-between border-b border-slate-200 bg-white px-8 py-4 md:flex">
                <h2 class="text-lg font-semibold text-slate-900">{{ $title ?? __('Dashboard') }}</h2>
            </header>

            <main class="flex-1 bg-sand-50 px-4 py-6 pb-24 md:px-8 md:py-8 md:pb-8">
                {{ $slot }}
            </main>

            {{-- Mobile bottom tab bar --}}
            <nav class="fixed inset-x-0 bottom-0 z-30 flex items-center justify-around border-t border-brand-600 bg-brand-700 py-2 md:hidden">
                @foreach ($bottomNavItems as $item)
                    @php $active = Route::has($item['route']) && request()->routeIs($item['route']); @endphp
                    @if (Route::has($item['route']))
                        <a href="{{ route($item['route']) }}" class="flex flex-col items-center gap-1 px-2 py-1 text-xs font-medium {{ $active ? 'text-white' : 'text-brand-100' }}">
                            <x-icon :name="$item['icon']" class="h-5 w-5" />
                            {{ __($item['label']) }}
                        </a>
                    @else
                        <span class="flex flex-col items-center gap-1 px-2 py-1 text-xs font-medium text-brand-400">
                            <x-icon :name="$item['icon']" class="h-5 w-5" />
                            {{ __($item['label']) }}
                        </span>
                    @endif
                @endforeach
                <button type="button" @click="mobileMenuOpen = true" class="flex flex-col items-center gap-1 px-2 py-1 text-xs font-medium text-brand-100">
                    <x-icon name="menu" class="h-5 w-5" />
                    {{ __('More') }}
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
            <div class="absolute inset-y-0 right-0 flex w-72 max-w-[85%] flex-col bg-brand-700 shadow-xl">
                <div class="flex items-center justify-between border-b border-brand-600 px-4 py-3">
                    <span class="text-lg font-semibold text-white">{{ config('app.name') }}</span>
                    <button type="button" @click="mobileMenuOpen = false" class="rounded-lg p-2 text-brand-100 hover:bg-brand-600 hover:text-white">
                        <x-icon name="close" class="h-6 w-6" />
                        <span class="sr-only">{{ __('Close menu') }}</span>
                    </button>
                </div>
                <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                    @foreach ($navItems as $item)
                        @php $active = Route::has($item['route']) && request()->routeIs($item['route']); @endphp
                        @if (Route::has($item['route']))
                            <a href="{{ route($item['route']) }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium {{ $active ? 'bg-brand-600 text-white' : 'text-brand-100 hover:bg-brand-600 hover:text-white' }}">
                                <x-icon :name="$item['icon']" class="h-5 w-5" />
                                {{ __($item['label']) }}
                            </a>
                        @else
                            <span class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-brand-400">
                                <x-icon :name="$item['icon']" class="h-5 w-5" />
                                {{ __($item['label']) }}
                            </span>
                        @endif
                    @endforeach
                </nav>
                <div class="border-t border-brand-600 p-3">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-brand-100 hover:bg-brand-600 hover:text-white">
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
