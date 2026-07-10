<div>
    <x-ui.page-header :title="__('Receipts')" :subtitle="__('Sales receipts ingested from POS terminals.')" />

    @if($receipts->isEmpty())
        <x-ui.empty-state :title="__('No receipts yet')" :description="__('Receipts will appear here once POS terminals start sending them.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($receipts as $receipt)
                <a href="{{ route('receipts.show', $receipt) }}" class="block">
                    <x-ui.card>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-slate-900">{{ $receipt->number }}</p>
                                <p class="text-sm text-slate-500">{{ $receipt->shop->name }} &middot; {{ $receipt->pos->name }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-slate-900">{{ number_format((float) $receipt->total, 2) }}</p>
                                <div class="mt-1 flex gap-1">
                                    <x-ui.badge :variant="$receipt->active ? 'success' : 'danger'">{{ $receipt->active ? __('Active') : __('Inactive') }}</x-ui.badge>
                                    <x-ui.badge :variant="$receipt->sell ? 'info' : 'warning'">{{ $receipt->sell ? __('Sell') : __('Refund') }}</x-ui.badge>
                                </div>
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
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Number') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Pos') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($receipts as $receipt)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $receipt->number }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $receipt->shop->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $receipt->pos->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $receipt->total, 2) }}</td>
                            <td class="px-4 py-3 text-sm">
                                <x-ui.badge :variant="$receipt->active ? 'success' : 'danger'">{{ $receipt->active ? __('Active') : __('Inactive') }}</x-ui.badge>
                                <x-ui.badge :variant="$receipt->sell ? 'info' : 'warning'">{{ $receipt->sell ? __('Sell') : __('Refund') }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" :href="route('receipts.show', $receipt)">{{ __('View') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $receipts->links() }}</div>
    @endif
</div>
