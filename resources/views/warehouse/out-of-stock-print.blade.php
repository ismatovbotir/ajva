@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Out of stock (main warehouse)') }} - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print { .no-print { display: none !important; } body { background: #fff; } tr, .grp { break-inside: avoid; } @page { margin: 12mm; } }
    </style>
</head>
<body class="bg-white p-6 text-sm text-slate-900">
    <div class="no-print mb-4"><button type="button" onclick="window.print()" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white">{{ __('Print') }}</button></div>
    <h1 class="text-lg font-semibold">{{ __('Out of stock (main warehouse)') }}</h1>
    <p class="text-slate-600" data-print-header>
        {{ $data['warehouse'] }} &middot; {{ __('Warehouse stock as of :date.', ['date' => $data['ref'] ?? '—']) }}
        @if($data['coverage'] !== null) {{ __('Sync coverage: :pct% of the previous sync.', ['pct' => round($data['coverage'] * 100)]) }} @endif
        {{ __(':count items', ['count' => $count]) }}
    </p>
    @if($data['state'] !== 'ok')
        <p class="mt-2 font-medium text-red-700">{{ $data['state'] === 'not_synced' ? __('Warehouse not synced since :date', ['date' => $data['ref']]) : __('No warehouse stock data yet') }}</p>
    @elseif($data['partial'])
        <p class="mt-2 font-medium text-amber-700">{{ __('The latest warehouse sync looks incomplete. The list below is only a warning, check the sync in 1C.') }}</p>
    @endif

    @foreach($byGroup as $group => $items)
        <section class="grp mt-5">
            <h2 class="mb-1 font-semibold">{{ $group }}</h2>
            <table class="w-full border-collapse text-xs">
                <thead><tr class="border-b border-slate-400 text-left">
                    <th class="py-1 pr-2">{{ __('Item') }}</th><th class="pr-2">{{ __('ABC') }}</th><th class="pr-2">{{ __('Reason') }}</th>
                    <th class="pr-2 text-right">{{ __('Last qty') }}</th><th class="pr-2 text-right">{{ __('Days out') }}</th>
                    <th class="pr-2 text-right">{{ __('Shops needing') }}</th><th class="pr-2 text-right">{{ __('Need') }}</th><th class="text-right">{{ __('Open orders') }}</th>
                </tr></thead>
                <tbody>
                @foreach($items as $r)
                    <tr class="border-b border-slate-200">
                        <td class="py-1 pr-2">{{ $r['name'] }} <span class="text-slate-500">{{ $r['sku'] }}</span></td>
                        <td class="pr-2">{{ $r['abc'] }}</td>
                        <td class="pr-2">{{ $r['reason'] === 'missing' ? __('Missing') : __('Zero') }}</td>
                        <td class="pr-2 text-right tabular-nums">{{ $fmt($r['last_qty']) }}</td>
                        <td class="pr-2 text-right tabular-nums">{{ $r['days_out'] }}</td>
                        <td class="pr-2 text-right tabular-nums">{{ $r['shops'] }}</td>
                        <td class="pr-2 text-right tabular-nums">{{ $fmt($r['need']) }}</td>
                        <td class="text-right tabular-nums">{{ $fmt($r['open_orders']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
    <p class="mt-6 text-xs text-slate-500">{{ __('Sales per day is the chain average of the last 7 days and can be understated when the shops were also out of stock.') }}</p>
</body>
</html>
