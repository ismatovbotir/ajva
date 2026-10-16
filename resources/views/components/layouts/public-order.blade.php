@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- Secret link page: keep it out of search indexes and Referer headers. --}}
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="referrer" content="no-referrer">
    {{-- Phone first: no side menu, the page draws its own fixed top bar. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2a5039">
    <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-sand-50 text-slate-900 antialiased">
    <main class="mx-auto max-w-xl">
        {{ $slot }}
    </main>
    <x-ui.confirm-modal />
    @livewireScripts
</body>
</html>
