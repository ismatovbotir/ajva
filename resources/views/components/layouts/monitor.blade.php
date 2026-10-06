@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="monitor-root">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>
    <style>
        /* Everything on the monitor is sized in rem, so the whole screen scales with the viewport. */
        html.monitor-root { font-size: 15px; }
        @media (min-width: 1024px) {
            html.monitor-root { font-size: clamp(12px, min(1.05vw, 1.85vh), 40px); }
        }
        html.monitor-root, html.monitor-root body { background: #020617; }
        .tabular { font-variant-numeric: tabular-nums; }
        @media (prefers-reduced-motion: no-preference) {
            .monitor-fade { animation: monitor-fade .6s ease-out; }
            @keyframes monitor-fade { from { opacity: .2; transform: translateY(-.3rem); } to { opacity: 1; transform: none; } }
            .monitor-bar { transition: height .6s ease, width .6s ease; }
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-slate-950 text-slate-100 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
