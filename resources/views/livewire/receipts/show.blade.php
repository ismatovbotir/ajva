<div>
    <x-ui.page-header :title="__('Receipt').' '.$receipt->number" :subtitle="$receipt->shop->name.' · '.$receipt->pos->name">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('receipts.index')">{{ __('Back to receipts') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.receipts._detail', ['receipt' => $receipt])
</div>
