<div>
    <x-ui.page-header :title="__('Stocks')" :subtitle="__('Current on-hand quantities per shop, synced from 1C.')" />

    @if($stocks->isEmpty())
        <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($stocks as $stock)
                <x-ui.card>
                    <p class="font-medium text-slate-900">{{ $stock->item->name }}</p>
                    <p class="text-sm text-slate-500">{{ $stock->shop->name }} &middot; {{ __('Qty') }}: {{ $stock->qty }}</p>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($stocks as $stock)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $stock->item->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $stock->shop->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $stock->qty }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $stocks->links() }}</div>
    @endif
</div>
