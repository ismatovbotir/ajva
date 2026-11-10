@php
    $fmt = function ($v, $unit) {
        $n = fn ($x, $d = 1) => rtrim(rtrim(number_format((float) $x, $d, '.', ' '), '0'), '.');
        return match ($unit) {
            '%' => $n($v).' %',
            'h' => $n($v).' '.__('h'),
            'money' => number_format((float) $v, 0, '.', ' '),
            default => $n($v, 0),
        };
    };
    $sevLabel = ['high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low')];
    $sevBadge = ['high' => 'danger', 'medium' => 'warning', 'low' => 'default'];
    $sevIcon = ['high' => 'exclamation', 'medium' => 'exclamation', 'low' => 'clock'];
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $field = 'block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm';
@endphp
<div>
    <x-ui.page-header :title="__('Security warnings')" :subtitle="__('Signals to review from receipts. A warning is never proof: check the evidence and the receipts.')" />

    <form wire:submit="generate" class="mb-6 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-44">
            <x-ui.label for="dateFrom">{{ __('From') }}</x-ui.label>
            <input type="date" id="dateFrom" wire:model="dateFrom" max="{{ now()->toDateString() }}" class="{{ $field }}" />
            <x-ui.error name="dateFrom" />
        </div>
        <div class="w-full sm:w-44">
            <x-ui.label for="dateTo">{{ __('To') }}</x-ui.label>
            <input type="date" id="dateTo" wire:model="dateTo" max="{{ now()->toDateString() }}" class="{{ $field }}" />
            <x-ui.error name="dateTo" />
        </div>

        <div class="relative w-full sm:w-72" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <x-ui.label>{{ __('Shops') }}</x-ui.label>
            <button type="button" @click="open = !open"
                    class="flex w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm shadow-sm focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                <span class="truncate">
                    @if(count($shopIds) === 0)
                        {{ __('All shops') }}
                    @else
                        {{ __(':count shops selected', ['count' => count($shopIds)]) }}
                    @endif
                </span>
                <span class="text-[10px] text-slate-400">▼</span>
            </button>
            <div x-show="open" x-cloak class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                @foreach($shops as $shop)
                    <label wire:key="shop-{{ $shop->id }}" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                        <input type="checkbox" wire:model="shopIds" value="{{ $shop->id }}" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />
                        <span class="truncate">{{ $shop->name }}</span>
                    </label>
                @endforeach
                <button type="button" wire:click="$set('shopIds', [])" class="mt-1 w-full rounded px-2 py-1.5 text-left text-xs text-slate-500 hover:bg-slate-50">{{ __('Clear selection (all shops)') }}</button>
            </div>
        </div>

        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="generate">
            <span wire:loading.remove wire:target="generate">{{ __('Generate') }}</span>
            <span wire:loading wire:target="generate">{{ __('Generating report') }}…</span>
        </x-ui.button>
    </form>

    @if($noShops)
        <x-ui.no-shops />
    @elseif(! $generated || $report === null)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick the period and shops, then press Generate.')" />
    @else
        @php $k = $report['kpi']; $st = $report['stamp']; @endphp

        <p class="mb-3 text-xs text-slate-500">
            {{ __('Checked :from to :to, :n receipts. As of :time.', ['from' => $st['from'], 'to' => $st['to'], 'n' => number_format($st['receipts'], 0, '.', ' '), 'time' => $st['generated_at']]) }}
        </p>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach([
                [__('High'), $k['high'], 'exclamation', __('warnings of high severity')],
                [__('Medium'), $k['medium'], 'exclamation', __('warnings of medium severity')],
                [__('Low'), $k['low'], 'clock', __('warnings of low severity')],
                [__('Affected'), $k['shops'], 'shield', __(':shops shops, :cashiers cashiers, :tills tills', ['shops' => $k['shops'], 'cashiers' => $k['cashiers'], 'tills' => $k['tills']])],
            ] as [$label, $value, $icon, $hint])
                <x-ui.card>
                    <p class="flex items-center gap-1.5 text-xs text-slate-500"><x-icon :name="$icon" class="h-4 w-4" />{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $value }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                </x-ui.card>
            @endforeach
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-44">
                <x-ui.label for="severity">{{ __('Severity') }}</x-ui.label>
                <select id="severity" wire:model.live="severity" class="{{ $field }}">
                    <option value="all">{{ __('All') }}</option>
                    @foreach($sevLabel as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-full sm:w-72">
                <x-ui.label for="type">{{ __('Warning type') }}</x-ui.label>
                <select id="type" wire:model.live="type" class="{{ $field }}">
                    <option value="all">{{ __('All') }}</option>
                    @foreach($types as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if($report['warnings'] === [])
            <x-ui.empty-state :title="__('No warnings in this period')"
                :description="__('Checked :from to :to, :n receipts. Nothing reached the thresholds.', ['from' => $st['from'], 'to' => $st['to'], 'n' => number_format($st['receipts'], 0, '.', ' ')])" />
        @else
            @if($report['truncated'])
                <p class="mb-2 text-xs text-amber-700">{{ __('Only the first :n warnings (most severe first) are shown.', ['n' => 300]) }}</p>
            @endif

            {{-- Mobile: cards --}}
            <div class="space-y-3 md:hidden">
                @foreach($report['warnings'] as $w)
                    <x-ui.card wire:key="wc-{{ $loop->index }}">
                        <div class="flex items-center justify-between gap-2">
                            <x-ui.badge :variant="$sevBadge[$w['severity']]"><x-icon :name="$sevIcon[$w['severity']]" class="mr-1 h-3.5 w-3.5" />{{ $sevLabel[$w['severity']] }}</x-ui.badge>
                            <span class="text-xs text-slate-500">{{ __($types[$w['type']]) }}</span>
                        </div>
                        <p class="mt-2 text-sm font-medium text-slate-900">{{ $w['finding'] }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ $w['evidence'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ __($w['metric']) }}: <span class="font-medium tabular-nums">{{ $fmt($w['value'], $w['unit']) }}</span>
                            @if($w['baseline'] !== null) · {{ __('Baseline') }}: <span class="tabular-nums">{{ $fmt($w['baseline'], $w['unit']) }}</span> @endif
                            @if($w['baseline_note']) ({{ $w['baseline_note'] }}) @endif
                            · {{ __('Sample') }}: {{ $w['sample'] }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            <a href="{{ $w['evidence_url'] }}" class="font-medium text-brand-700 hover:underline">{{ __('Review') }}</a>
                            @foreach($w['receipt_ids'] as $rid)
                                <a href="{{ route('receipts.show', ['receipt' => $rid]) }}" class="text-slate-600 underline">#{{ $rid }}</a>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-ui.card padding="p-0" class="hidden md:block">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead>
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Severity') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Finding') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Value') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Baseline') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Sample') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Receipts') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            @foreach($report['warnings'] as $w)
                                <tr wire:key="wr-{{ $loop->index }}" class="align-top">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <x-ui.badge :variant="$sevBadge[$w['severity']]"><x-icon :name="$sevIcon[$w['severity']]" class="mr-1 h-3.5 w-3.5" />{{ $sevLabel[$w['severity']] }}</x-ui.badge>
                                        <p class="mt-1 text-xs text-slate-500">{{ __($types[$w['type']]) }}</p>
                                    </td>
                                    <td class="px-3 py-2">
                                        <p class="font-medium text-slate-900">{{ $w['finding'] }}</p>
                                        <p class="mt-0.5 text-xs text-slate-600">{{ $w['evidence'] }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                                        {{ $fmt($w['value'], $w['unit']) }}
                                        <p class="text-xs text-slate-500">{{ __($w['metric']) }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        @if($w['baseline'] !== null) {{ $fmt($w['baseline'], $w['unit']) }} @else - @endif
                                        @if($w['baseline_note'])<p class="text-xs text-slate-500">{{ $w['baseline_note'] }}</p>@endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $w['sample'] }}</td>
                                    <td class="px-3 py-2">
                                        <a href="{{ $w['evidence_url'] }}" class="font-medium text-brand-700 hover:underline">{{ __('Review') }}</a>
                                        <div class="mt-1 flex flex-wrap gap-x-2 text-xs">
                                            @foreach($w['receipt_ids'] as $rid)
                                                <a href="{{ route('receipts.show', ['receipt' => $rid]) }}" class="text-slate-600 underline">#{{ $rid }}</a>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <details class="mt-6 text-sm text-slate-600">
            <summary class="cursor-pointer font-medium">{{ __('What is not checked') }}</summary>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($report['notes'] as $note)
                    <li><span class="font-medium">{{ $note['signal'] }}</span>: {{ $note['reason'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
