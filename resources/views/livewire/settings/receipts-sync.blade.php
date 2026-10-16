<div class="max-w-3xl space-y-6">
    <x-ui.page-header :title="__('Receipts migration (1C)')" :subtitle="__('1C sends the old receipts in batches. It asks for the last receipt date and time and continues after it.')" />

    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-slate-900">{{ __('Last receipt date and time') }}</h2>
        <p class="text-sm text-slate-600">{{ __('One value for all shops. It moves forward by itself after every batch. Set it back to import a period again (sending a receipt twice is safe), or clear it to start from the beginning.') }}</p>
        <form wire:submit="save" class="flex flex-wrap items-end gap-3">
            <div>
                <x-ui.label for="lastReceiptAt">{{ __('Last receipt date and time') }}</x-ui.label>
                <input type="datetime-local" step="1" id="lastReceiptAt" wire:model="lastReceiptAt"
                       class="block rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm" />
                <x-ui.error name="lastReceiptAt" />
            </div>
            <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            <x-ui.button type="button" variant="secondary" wire:click="clear" wire:confirm="{{ __('Clear the date? 1C will start again from the first receipt.') }}">{{ __('Start from the beginning') }}</x-ui.button>
            @if($saved)<span class="text-sm text-brand-700" role="status">{{ __('Saved') }}</span>@endif
        </form>
        @if($state['last_receipt_at'] === null)
            <p class="text-sm text-amber-700">{{ __('Not set yet: 1C will send the receipts from the very beginning.') }}</p>
        @endif
    </x-ui.card>

    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-slate-900">{{ __('Addresses for 1C') }}</h2>
        <p class="text-sm text-slate-600">{{ __('Local network only, no token (the same rule as the items sync).') }}</p>
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="font-medium text-slate-800">GET {{ __('last receipt date and time') }}</dt>
                <dd><code class="break-all rounded bg-sand-50 px-2 py-1 text-slate-800">{{ $lastUrl }}</code></dd>
                <dd class="mt-1 text-xs text-slate-500">{{ __('Answer: last_receipt_at (Y-m-d H:i:s or null), date (d.m.y), time (H:i:s), batch_size.') }}</dd>
            </div>
            <div>
                <dt class="font-medium text-slate-800">POST {{ __('batch of receipts') }}</dt>
                <dd><code class="break-all rounded bg-sand-50 px-2 py-1 text-slate-800">{{ $batchUrl }}</code></dd>
                <dd class="mt-1 text-xs text-slate-500">{{ __('A JSON array of up to :n receipts in the same structure as the receipts of the tills. A receipt is identified by pos + session + number. A receipt that cannot be stored (for example an item that is not in the catalog) is rejected and reported in the answer; the others are kept. The date moves to the newest accepted receipt.', ['n' => $batchSize]) }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <x-ui.card class="space-y-3">
        <h2 class="text-base font-semibold text-slate-900">{{ __('Newest batch') }}</h2>
        @if($state['last_batch'])
            <p class="text-sm text-slate-700" data-last-batch>{{ __(':at: :ok accepted, :bad rejected.', ['at' => $state['last_batch']['at'], 'ok' => $state['last_batch']['accepted'], 'bad' => $state['last_batch']['rejected']]) }}</p>
        @else
            <p class="text-sm text-slate-500">{{ __('No batch received yet.') }}</p>
        @endif

        @if($state['rejected'] !== [])
            <h3 class="pt-2 text-sm font-semibold text-slate-700">{{ __('Rejected receipts (newest first)') }}</h3>
            <div class="max-h-72 overflow-auto rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500"><th class="px-3 py-2">POS</th><th class="px-3 py-2">{{ __('Session') }}</th><th class="px-3 py-2">{{ __('Number') }}</th><th class="px-3 py-2">{{ __('Reason') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($state['rejected'] as $r)
                            <tr><td class="px-3 py-2">{{ $r['pos'] }}</td><td class="px-3 py-2">{{ $r['session'] }}</td><td class="px-3 py-2">{{ $r['number'] }}</td><td class="px-3 py-2 text-red-700">{{ $r['reason'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
