<div>
    <x-ui.page-header :title="$shop->name" :subtitle="'#'.$shop->id">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('shops.index')">{{ __('Back to shops') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <h2 class="mb-3 text-lg font-semibold text-slate-900">{{ __('Items in this shop') }}</h2>

    @if($stocks->isEmpty())
        <x-ui.empty-state :title="__('No stock at this shop yet')" />
    @else
        <x-ui.card padding="p-0">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Mark') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($stocks as $stock)
                        <tr class="cursor-pointer hover:bg-sand-50" onclick="window.location='{{ route('items.show', $stock->item) }}'">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                <a href="{{ route('items.show', $stock->item) }}" class="hover:text-accent hover:underline">{{ $stock->item->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $stock->item->mark ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $stock->qty }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif
</div>
