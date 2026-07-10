<div>
    <x-ui.page-header :title="__('Shops')" :subtitle="__('Shops synced from 1C, with their current stock.')" />

    @if($shops->isEmpty())
        <x-ui.empty-state :title="__('No shops yet')" :description="__('Shops will appear here once 1C sends stock data for them.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($shops as $shop)
                <a href="{{ route('shops.show', $shop) }}" class="block">
                    <x-ui.card>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-slate-900">{{ $shop->name }}</p>
                                <p class="text-sm text-slate-500">#{{ $shop->id }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-slate-900">{{ number_format((float) $shop->qty_total) }} {{ __('qty') }}</p>
                                <p class="text-sm text-slate-500">{{ $shop->item_count }} {{ __('items') }}</p>
                            </div>
                        </div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Items') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total qty') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($shops as $shop)
                        <tr class="cursor-pointer hover:bg-sand-50" onclick="window.location='{{ route('shops.show', $shop) }}'">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                <a href="{{ route('shops.show', $shop) }}" class="hover:text-brand-700 hover:underline">{{ $shop->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $shop->item_count }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $shop->qty_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $shops->links() }}</div>
    @endif
</div>
