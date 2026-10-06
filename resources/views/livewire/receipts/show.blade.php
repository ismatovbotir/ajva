<div>
    <x-ui.page-header :title="__('Receipt').' '.$receipt->number" :subtitle="$receipt->shop->name.' · '.$receipt->pos->name">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('receipts.index')">{{ __('Back to receipts') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mb-6">
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Number') }}</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $receipt->number }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Date') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $receipt->created_at->format('d.m.Y') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Time') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $receipt->created_at->format('H:i:s') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Client') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $receipt->client ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Cashier') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ $receipt->cashier ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ number_format((float) $receipt->total, 2) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</dt>
                <dd class="mt-1 text-sm text-slate-900">{{ number_format((float) $receipt->discount, 2) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</dt>
                <dd class="mt-1 flex gap-1">
                    <x-ui.badge :variant="$receipt->active ? 'success' : 'danger'">{{ $receipt->active ? __('Active') : __('Inactive') }}</x-ui.badge>
                    <x-ui.badge :variant="$receipt->sell ? 'info' : 'warning'">{{ $receipt->sell ? __('Sell') : __('Refund') }}</x-ui.badge>
                </dd>
            </div>
        </dl>
    </x-ui.card>

    <h2 class="mb-3 text-lg font-semibold text-slate-900">{{ __('Items') }}</h2>
    @if($receipt->items->isEmpty())
        <x-ui.empty-state :title="__('No items on this receipt')" />
    @else
        <x-ui.card padding="p-0" class="mb-6">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Price') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Discount') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($receipt->items as $receiptItem)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $receiptItem->item->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $receiptItem->qty }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $receiptItem->price, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $receiptItem->discount, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $receiptItem->total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif

    <h2 class="mb-3 text-lg font-semibold text-slate-900">{{ __('Payments') }}</h2>
    @if($receipt->payments->isEmpty())
        <x-ui.empty-state :title="__('No payments on this receipt')" />
    @else
        <x-ui.card padding="p-0">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Payment method') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Value') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($receipt->payments as $payment)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $payment->payment }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ number_format((float) $payment->value, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif
</div>
