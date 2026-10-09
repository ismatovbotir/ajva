@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format($n, 3, '.', ' '), '0'), '.');
    $arrow = fn ($col) => $sortBy === $col ? ($sortDir === 'asc' ? '▲' : '▼') : '↕';
@endphp
<div>
    <x-ui.page-header :title="__('Analytics')" :subtitle="__('Stock left after the selected day\'s sales, per shop. Only items sold by successful sale receipts are listed.')" />

    <div class="mb-6 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-56">
            <x-ui.label for="date">{{ __('Date') }}</x-ui.label>
            <input type="date" id="date" wire:model.live="date" max="{{ now()->toDateString() }}"
                   class="block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
        </div>
        <x-ui.button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
            {{ __('Generate') }}
        </x-ui.button>
    </div>

    @if($noShops)
        <x-ui.no-shops />
    @elseif(! $generated)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick a date and press Generate.')" />
    @elseif($tabs->isEmpty())
        <x-ui.empty-state :title="__('No shops yet')" :description="__('Shops will appear here once 1C sends stock data for them.')" />
    @else
        {{-- Shop tabs --}}
        <div class="mb-4 overflow-x-auto border-b border-slate-200">
            <nav class="-mb-px flex gap-1" role="tablist">
                @foreach($tabs as $tab)
                    <button type="button" role="tab" wire:key="tab-{{ $tab['id'] }}" wire:click="selectShop({{ $tab['id'] }})"
                            aria-selected="{{ $tab['id'] === $shopId ? 'true' : 'false' }}"
                            class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition {{ $tab['id'] === $shopId ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                        {{ $tab['name'] }}
                    </button>
                @endforeach
            </nav>
        </div>

        <div wire:loading.class="opacity-60" wire:target="selectShop,sort" class="transition-opacity duration-150">
        <p class="mb-3 hidden text-sm text-slate-500 md:block">{{ __('Click a column header to sort.') }} {{ __('Stock is the latest snapshot on or before the selected date.') }}</p>
        <div class="mb-3 flex flex-wrap gap-1.5 md:hidden">
            @foreach([['item', 'Item'], ['stock', 'Stock'], ['sold', 'Net sold'], ['remaining', 'Remaining']] as [$col, $label])
                <button type="button" wire:click="sort('{{ $col }}')"
                        class="rounded-full border px-3 py-1 text-xs font-medium {{ $sortBy === $col ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700' }}">
                    {{ __($label) }} {{ $arrow($col) }}
                </button>
            @endforeach
        </div>

        {{-- Mobile card list --}}
        <div class="max-h-[65vh] space-y-3 overflow-y-auto md:hidden">
            @foreach($rows as $row)
                <x-ui.card>
                    @if($row['group'])
                        <p class="text-xs text-slate-500">{{ $row['group'] }}</p>
                    @endif
                    <p class="font-medium text-slate-900">{{ $row['item'] }}</p>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div><dt class="text-xs text-slate-500">{{ __('Stock') }}</dt><dd class="tabular-nums">{{ $fmt($row['stock']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Stock date') }}</dt><dd class="tabular-nums">{{ $row['stock_date'] ? \Illuminate\Support\Carbon::parse($row['stock_date'])->format('d.m.Y') : '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Net sold') }}</dt><dd><button type="button" wire:click="showReceipts({{ $row['item_id'] }})" class="font-medium tabular-nums text-brand-700 underline decoration-dotted underline-offset-2">{{ $fmt($row['sold']) }}</button></dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Remaining') }}</dt><dd class="font-semibold tabular-nums {{ $row['remaining'] < 0 ? 'text-red-700' : '' }}">{{ $fmt($row['remaining']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Min') }}</dt><dd class="tabular-nums">{{ $fmt($row['min']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Max') }}</dt><dd class="tabular-nums">{{ $fmt($row['max']) }}</dd></div>
                    </dl>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <div class="max-h-[65vh] overflow-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="sticky top-0 z-10 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                        @foreach([['item', 'Item', 'text-left'], ['stock', 'Stock', 'text-right'], ['sold', 'Net sold', 'text-right'], ['remaining', 'Remaining', 'text-right']] as [$col, $label, $align])
                            <th class="px-4 py-3 {{ $align }} text-xs font-semibold uppercase tracking-wide text-slate-500" aria-sort="{{ $sortBy === $col ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <button type="button" wire:click="sort('{{ $col }}')" class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-slate-800 {{ $sortBy === $col ? 'text-brand-700' : '' }}">
                                    {{ __($label) }}<span class="text-[10px]">{{ $arrow($col) }}</span>
                                </button>
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Stock date') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Min') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Max') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($rows as $row)
                        <tr>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $row['group'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['item'] }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['stock']) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums">
                                <button type="button" wire:click="showReceipts({{ $row['item_id'] }})" class="font-medium text-brand-700 underline decoration-dotted underline-offset-2 hover:text-brand-700">{{ $fmt($row['sold']) }}</button>
                            </td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums {{ $row['remaining'] < 0 ? 'text-red-700' : 'text-slate-700' }}">{{ $fmt($row['remaining']) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $row['stock_date'] ? \Illuminate\Support\Carbon::parse($row['stock_date'])->format('d.m.Y') : '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['min']) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['max']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-ui.card>
        </div>
    @endif

    <x-ui.modal :show="$modalReceipts !== null" :title="__('Receipts').' — '.$modalItemName" class="max-w-3xl" wire:keydown.escape.window="closeModal">
        @if($modalReceipts && $modalReceipts->isNotEmpty())
            <div class="max-h-[60vh] overflow-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="sticky top-0 bg-sand-50 shadow-[0_1px_0_0_#e2e8f0]">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Receipt') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Cashier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($modalReceipts as $receipt)
                            <tr wire:key="mr-{{ $receipt->id }}">
                                <td class="whitespace-nowrap px-3 py-2 text-sm text-slate-500">{{ $receipt->created_at->format('d.m.Y H:i:s') }}</td>
                                <td class="px-3 py-2 text-sm font-medium">
                                    <a href="{{ route('receipts.show', $receipt) }}" target="_blank" rel="noopener" class="text-brand-700 hover:underline">{{ $receipt->number }}</a>
                                </td>
                                <td class="px-3 py-2 text-sm text-slate-500">{{ $receipt->cashier ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm"><x-ui.badge :variant="$receipt->active ? 'success' : 'danger'">{{ $receipt->active ? __('Active') : __('Inactive') }}</x-ui.badge></td>
                                <td class="px-3 py-2 text-sm"><x-ui.badge :variant="$receipt->sell ? 'info' : 'warning'">{{ $receipt->sell ? __('Sell') : __('Refund') }}</x-ui.badge></td>
                                <td class="px-3 py-2 text-right text-sm tabular-nums text-slate-700">{{ number_format((float) $receipt->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-ui.empty-state :title="__('No receipts for this day')" />
        @endif
    </x-ui.modal>

    {{-- Shown only while Generate builds every shop's table; closes by itself when the report is ready.
         Livewire's stylesheet only hides a few exact wire:loading forms, not combined modifiers like
         .flex.delay.shorter, so it must be hidden explicitly or it would cover the page on load. --}}
    <div wire:loading.flex.delay.shorter wire:target="generate" style="display: none;"
         class="fixed inset-0 z-50 items-center justify-center px-4 py-6"
         role="alertdialog" aria-modal="true" aria-labelledby="generating-title" aria-describedby="generating-text">
        <div class="modal-backdrop fixed inset-0 bg-slate-900/50"></div>
        <div class="modal-panel relative w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-xl">
            <svg class="spinner mx-auto h-12 w-12 text-brand-700" viewBox="0 0 50 50" fill="none" aria-hidden="true">
                <circle cx="25" cy="25" r="20" stroke="currentColor" stroke-opacity="0.2" stroke-width="5"></circle>
                <path d="M45 25a20 20 0 0 0-20-20" stroke="currentColor" stroke-width="5" stroke-linecap="round"></path>
            </svg>
            <h2 id="generating-title" class="mt-4 text-lg font-semibold text-slate-900">{{ __('Generating report…') }}</h2>
            <p id="generating-text" class="mt-1 text-sm text-slate-500">{{ __('Calculating sales and stock for every shop. This can take a few seconds.') }}</p>
            <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                <div class="indeterminate-bar h-full w-1/3 rounded-full bg-brand-600"></div>
            </div>
        </div>
    </div>
</div>
