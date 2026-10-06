@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format($n, 3, '.', ' '), '0'), '.');
@endphp
<div>
    <x-ui.page-header :title="__('Analytics')" :subtitle="__('Stock left after the selected day\'s net sales, per shop and item.')" />

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

    @if(! $generated)
        <x-ui.empty-state :title="__('No report yet')" :description="__('Pick a date and press Generate.')" />
    @elseif($tabs->isEmpty())
        <x-ui.empty-state :title="__('No sales for this day')" :description="__('Pick another date to see its sales.')" />
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

        <p class="mb-3 text-sm text-[#52514e]">{{ __('Sorted by stock minus net sold qty, lowest first.') }}</p>

        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($rows as $row)
                <x-ui.card>
                    <p class="font-medium text-slate-900">{{ $row['item'] }}</p>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div><dt class="text-xs text-slate-500">{{ __('Stock') }}</dt><dd class="tabular-nums">{{ $fmt($row['stock']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Net sold') }}</dt><dd class="tabular-nums">{{ $fmt($row['sold']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Remaining') }}</dt><dd class="font-semibold tabular-nums {{ $row['remaining'] < 0 ? 'text-red-700' : '' }}">{{ $fmt($row['remaining']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Min') }}</dt><dd class="tabular-nums">{{ $fmt($row['min']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Max') }}</dt><dd class="tabular-nums">{{ $fmt($row['max']) }}</dd></div>
                    </dl>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Stock') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Net sold') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Remaining') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Min') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Max') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($rows as $row)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['item'] }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['stock']) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['sold']) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums {{ $row['remaining'] < 0 ? 'text-red-700' : 'text-slate-700' }}">{{ $fmt($row['remaining']) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['min']) }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ $fmt($row['max']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</div>
