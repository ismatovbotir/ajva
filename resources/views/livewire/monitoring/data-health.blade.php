@php
    $variant = ['ok' => 'success', 'warn' => 'warning', 'bad' => 'danger', 'na' => 'default'];
    $statusText = ['ok' => __('Normal'), 'warn' => __('Low'), 'bad' => __('Silent'), 'na' => __('Not enough history')];
    $hours = fn ($v) => $v === null ? '—' : ($v < 1 ? '< 1 '.__('h') : number_format($v, 0, '.', ' ').' '.__('h'));
    $rej = $h['rejections'];
    $reasonLabels = \App\Support\ReceiptRejections::labels();
    $flagLabels = \App\Support\ReceiptChecks::flagLabels();
@endphp
<div wire:poll.60s>
    <x-ui.page-header :title="__('Data health')" :subtitle="__('Is the receipt feed complete and clean? Wrong receipt data means wrong order quantities.')" />

    @if($h['silent_count'] > 0 || $rej['spike'])
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-red-700 ring-1 ring-inset ring-red-600/20" data-overall="problems" role="alert">
            <div class="flex items-center gap-3">
                <x-icon name="exclamation" class="h-6 w-6 shrink-0" />
                <div class="text-sm">
                    @if($h['silent_count'] > 0)
                        <p class="font-semibold">{{ __(':count shops look silent: far fewer receipts than usual in the last 24 hours.', ['count' => $h['silent_count']]) }}</p>
                    @endif
                    @if($rej['spike'])
                        <p class="font-semibold">{{ __(':count receipts were rejected in the last 24 hours, far more than usual.', ['count' => $rej['total24']]) }}</p>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="mb-4 flex items-center gap-3 rounded-lg bg-brand-50 px-4 py-3 text-brand-700 ring-1 ring-inset ring-brand-600/20" data-overall="ok" role="status">
            <x-icon name="check" class="h-6 w-6 shrink-0" />
            <p class="font-semibold">{{ __('The receipt feed looks healthy') }}</p>
        </div>
    @endif

    {{-- Shops --}}
    <x-ui.card padding="p-0" class="mb-4">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200" data-shops>
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Last receipt') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Feed lag') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Receipts, last 24 h') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Usual day') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Tills active') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($h['shops'] as $s)
                        <tr data-shop="{{ $s['id'] }}" data-status="{{ $s['status'] }}">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $s['name'] }}</td>
                            <td class="px-4 py-3 text-sm text-slate-700">{{ $s['last_at'] ? \Illuminate\Support\Carbon::parse($s['last_at'])->format('d.m H:i') : __('Never') }}</td>
                            <td class="px-4 py-3 text-sm"><x-ui.badge :variant="$variant[$s['lag_status']] ?? 'default'">{{ $hours($s['lag_hours']) }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-sm text-slate-700">{{ $s['last24'] }}@if($s['ratio'] !== null) <span class="text-xs text-slate-500">({{ round($s['ratio'] * 100) }} %)</span>@endif</td>
                            <td class="px-4 py-3 text-sm text-slate-700">{{ $s['usual'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-700">{{ $s['tills_active'] }} / {{ $s['tills_registered'] }}</td>
                            <td class="px-4 py-3 text-sm"><x-ui.badge :variant="$variant[$s['status']]">{{ $statusText[$s['status']] }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500">{{ __('No shops in view.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">{{ __('Usual day = the median of the sale receipts per day over the last 28 days. A shop is judged only with a usual day of at least :n receipts.', ['n' => (int) config('receipts.health.min_usual', 20)]) }}</p>
    </x-ui.card>

    <div class="grid gap-4 md:grid-cols-2">
        {{-- Rejections --}}
        <x-ui.card data-rejections>
            <h2 class="text-sm font-semibold text-slate-900">{{ __('Rejected receipts') }}</h2>
            <p class="mt-1 text-2xl font-semibold text-slate-900" data-rejected-24h>{{ $rej['total24'] }} <span class="text-sm font-normal text-slate-500">{{ __('in the last 24 hours') }}</span></p>
            @if($rej['by_reason'] !== [])
                <ul class="mt-2 space-y-0.5 text-sm text-slate-700">
                    @foreach($rej['by_reason'] as $reason => $n)
                        <li>{{ __($reasonLabels[$reason] ?? $reason) }}: <span class="font-medium">{{ $n }}</span></li>
                    @endforeach
                </ul>
            @endif
            @if($rej['top_items'] !== [])
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item ids missing from the catalog') }}</p>
                <p class="text-sm text-slate-700">
                    @foreach($rej['top_items'] as $i)
                        <span class="mr-2">#{{ $i['item_id'] }} <span class="text-slate-500">&times;{{ $i['count'] }}</span></span>
                    @endforeach
                </p>
            @endif
            @if($rej['daily'] !== [])
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Last 7 days') }}</p>
                <p class="text-sm text-slate-700">
                    @foreach($rej['daily'] as $date => $byReason)
                        <span class="mr-3">{{ \Illuminate\Support\Carbon::parse($date)->format('d.m') }}: <span class="font-medium">{{ array_sum($byReason) }}</span></span>
                    @endforeach
                </p>
            @endif
            <p class="mt-3 text-xs text-slate-500">{{ __('The newest :n rejected receipts are kept.', ['n' => (int) config('receipts.keep_rejections', 500)]) }}</p>
        </x-ui.card>

        {{-- Late + flags --}}
        <x-ui.card data-late>
            <h2 class="text-sm font-semibold text-slate-900">{{ __('Late and backfilled receipts') }}</h2>
            @if($h['late']['tracked_since'] === null)
                <p class="mt-1 text-sm text-slate-500">{{ __('No ingestion time recorded yet: it is stored for receipts that arrive from now on.') }}</p>
            @else
                <p class="mt-1 text-2xl font-semibold text-slate-900" data-late-24h>{{ $h['late']['late24'] }} <span class="text-sm font-normal text-slate-500">{{ __('in the last 24 hours') }}</span></p>
                <p class="text-sm text-slate-700">{{ __(':late of :arrived till receipts of the last 7 days arrived more than :h hours after their own time.', ['late' => $h['late']['late7'], 'arrived' => $h['late']['arrived7'], 'h' => $h['late']['hours']]) }}</p>
            @endif

            <h2 class="mt-4 text-sm font-semibold text-slate-900">{{ __('Receipts with a data warning (7 days)') }}</h2>
            <p class="mt-1 text-2xl font-semibold text-slate-900" data-flagged>{{ $h['flags']['receipts'] }}</p>
            @if($h['flags']['codes'] !== [])
                <ul class="mt-1 space-y-0.5 text-sm text-slate-700">
                    @foreach($h['flags']['codes'] as $code => $n)
                        <li>{{ __($flagLabels[$code] ?? $code) }}: <span class="font-medium">{{ $n }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- Freshness --}}
        <x-ui.card data-freshness>
            <h2 class="text-sm font-semibold text-slate-900">{{ __('Stock and sales freshness') }}</h2>
            <dl class="mt-2 space-y-1 text-sm text-slate-700">
                <div class="flex justify-between gap-3"><dt>{{ __('Newest receipt') }}</dt><dd class="font-medium">{{ $h['freshness']['receipt_at'] ? \Illuminate\Support\Carbon::parse($h['freshness']['receipt_at'])->format('d.m H:i') : '—' }} <span class="text-slate-500">({{ $hours($h['freshness']['receipt_hours']) }})</span></dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('Newest 1C stock date') }}</dt><dd class="font-medium">{{ $h['freshness']['stock_date'] ?? '—' }} <span class="text-slate-500">({{ $hours($h['freshness']['stock_hours']) }})</span></dd></div>
            </dl>
            <p class="mt-2 text-xs text-slate-500">{{ __('Stock age is counted from the end of the stock date, as only the day is stored.') }}</p>
        </x-ui.card>

        {{-- History cursor --}}
        <x-ui.card data-cursor>
            <h2 class="text-sm font-semibold text-slate-900">{{ __('1C history migration') }}</h2>
            <dl class="mt-2 space-y-1 text-sm text-slate-700">
                <div class="flex justify-between gap-3"><dt>{{ __('Cursor (receipt time)') }}</dt><dd class="font-medium">{{ $h['cursor']['last_receipt_at'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('Newest batch') }}</dt><dd class="font-medium">{{ $h['cursor']['last_batch_at'] ?? '—' }} @if($h['cursor']['batch_age_hours'] !== null)<span class="text-slate-500">({{ $hours($h['cursor']['batch_age_hours']) }})</span>@endif</dd></div>
            </dl>
        </x-ui.card>
    </div>
</div>
