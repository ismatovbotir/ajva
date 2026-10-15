@props(['title' => null, 'public' => false])
<!DOCTYPE html>
<html lang="en" class="monitor-root">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' - '.config('app.name') : config('app.name') }}</title>
    <style>
        /* Everything on the monitor is sized in rem and the screen is a FIXED 16:9 stage of 96rem x 54rem (the TV ratio):
           1rem = min(width/96, height/54), so it fills a 16:9 TV exactly and shows black bars on any other ratio. */
        html.monitor-root { font-size: 15px; }
        @media (min-width: 1024px) {
            html.monitor-root { font-size: min(calc(100vw / 96), calc(100vh / 54)); }
        }
        html.monitor-root, html.monitor-root body { background: var(--color-slate-950); }
        .tabular { font-variant-numeric: tabular-nums; }
        @media (prefers-reduced-motion: no-preference) {
            .monitor-fade { animation: monitor-fade .6s ease-out; }
            @keyframes monitor-fade { from { opacity: .2; transform: translateY(-.3rem); } to { opacity: 1; transform: none; } }
            .monitor-bar { transition: height .6s ease, width .6s ease; }
        }
    </style>
    {{-- Saved Light/Dark choice (default dark) applied before first paint, on every monitor screen. --}}
        <script>
            (function () {
                try {
                    if (localStorage.getItem('monitorTheme') === 'light') {
                        document.documentElement.setAttribute('data-monitor-theme', 'light');
                    }
                } catch (e) {}
            })();
        </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-slate-950 text-slate-100 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
