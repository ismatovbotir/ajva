<div class="space-y-6">
    <x-ui.page-header :title="__('Warehouse')" :subtitle="__('Choose which shop is the main warehouse.')" />

    @unless($configured)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            {{ __('The main warehouse is not set yet. Warehouse monitors will show every selected shop, including the warehouse itself, until you choose it here.') }}
        </div>
    @endunless

    <x-ui.card>
        <form wire:submit="save" class="space-y-4">
            <div>
                <x-ui.select name="shopId" :label="__('Main warehouse')" wire:model="shopId">
                    <option value="">{{ __('Not set') }}</option>
                    @foreach($shops as $shop)
                        <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                    @endforeach
                </x-ui.select>
                <p class="mt-1 text-xs text-slate-500">{{ __('The main warehouse is never one of the shops to replenish on a Warehouse monitor.') }}</p>
            </div>

            <div class="flex items-center justify-end gap-3">
                @if($saved)
                    <span class="text-sm text-brand-700">{{ __('Saved') }}</span>
                @endif
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
