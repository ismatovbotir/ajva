<div>
    <x-ui.page-header :title="__('Prices')" :subtitle="__('Price types, synced from 1C.')" />

    @if($prices->isEmpty())
        <x-ui.empty-state :title="__('No prices yet')" :description="__('Prices are created automatically from 1C sync.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($prices as $price)
                <x-ui.card>
                    <p class="font-medium text-slate-900">{{ $price->name }}</p>
                    <x-ui.badge :variant="$price->is_sell ? 'success' : 'default'">
                        {{ $price->is_sell ? __('Sell price') : __('Not a sell price') }}
                    </x-ui.badge>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ID') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Sell price') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($prices as $price)
                        <tr>
                            <td class="px-4 py-3 text-sm text-slate-500">#{{ $price->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $price->name }}</td>
                            <td class="px-4 py-3 text-sm">
                                <x-ui.badge :variant="$price->is_sell ? 'success' : 'default'">
                                    {{ $price->is_sell ? __('Yes') : __('No') }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $prices->links() }}</div>
    @endif
</div>
