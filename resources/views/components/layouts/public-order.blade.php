@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- Secret link page: keep it out of search indexes and Referer headers. --}}
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-sand-50 text-slate-900 antialiased">
    <header class="bg-brand-700 px-4 py-3 text-white">
        <div class="mx-auto max-w-4xl text-lg font-semibold">{{ config('app.name') }}</div>
    </header>
    <main class="mx-auto max-w-4xl px-3 py-5 sm:px-4">
        {{ $slot }}
    </main>
    <x-ui.confirm-modal />
    @livewireScripts
</body>
</html>
