@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>
    <x-theme-script />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-screen items-center justify-center bg-sand-50 px-4 antialiased">
    <div class="fixed right-3 top-3"><x-ui.theme-toggle /></div>
    {{ $slot }}
    @livewireScripts
</body>
</html>
