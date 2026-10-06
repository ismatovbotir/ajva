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
    @elseif($rows->total() === 0)
        <x-ui.empty-state :title="__('No sales for this day')" :description="__('Pick another date to see its sales.')" />
    @else
        <p class="mb-3 text-sm text-[#52514e]">{{ __('Sorted by stock minus net sold qty, highest first.') }}</p>

        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($rows as $row)
                <x-ui.card>
                    <p class="font-medium text-slate-900">{{ $row['item'] }}</p>
                    <p class="text-sm text-slate-500">{{ $row['shop'] }}</p>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div><dt class="text-xs text-slate-500">{{ __('Stock') }}</dt><dd class="tabular-nums">{{ rtrim(rtrim(number_format($row['stock'], 3, '.', ' '), '0'), '.') }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Net sold') }}</dt><dd class="tabular-nums">{{ rtrim(rtrim(number_format($row['sold'], 3, '.', ' '), '0'), '.') }}</dd></div>
                        <div><dt class="text-xs text-slate-500">{{ __('Remaining') }}</dt><dd class="font-semibold tabular-nums {{ $row['remaining'] < 0 ? 'text-red-700' : '' }}">{{ rtrim(rtrim(number_format($row['remaining'], 3, '.', ' '), '0'), '.') }}</dd></div>
                    </dl>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Stock') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Net sold') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Remaining') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($rows as $row)
                        <tr>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $row['shop'] }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['item'] }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ rtrim(rtrim(number_format($row['stock'], 3, '.', ' '), '0'), '.') }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-500">{{ rtrim(rtrim(number_format($row['sold'], 3, '.', ' '), '0'), '.') }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold tabular-nums {{ $row['remaining'] < 0 ? 'text-red-700' : 'text-slate-700' }}">{{ rtrim(rtrim(number_format($row['remaining'], 3, '.', ' '), '0'), '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</div>
