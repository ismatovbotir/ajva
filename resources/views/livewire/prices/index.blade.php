<div>
    <x-ui.page-header :title="__('Prices')" :subtitle="__('Manage price types.')">
        <x-slot:actions>
            <x-ui.button wire:click="create">{{ __('New price') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($prices->isEmpty())
        <x-ui.empty-state :title="__('No prices yet')" :description="__('Create your first price type to get started.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($prices as $price)
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-slate-900">{{ $price->name }}</p>
                            <x-ui.badge :variant="$price->is_sell ? 'success' : 'default'">
                                {{ $price->is_sell ? __('Sell price') : __('Not a sell price') }}
                            </x-ui.badge>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="secondary" wire:click="edit({{ $price->id }})">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button variant="danger" wire:click="delete({{ $price->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                        </div>
                    </div>
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
                        <th class="px-4 py-3"></th>
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
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" wire:click="edit({{ $price->id }})">{{ __('Edit') }}</x-ui.button>
                                <x-ui.button variant="danger" wire:click="delete({{ $price->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $prices->links() }}</div>
    @endif

    <x-ui.modal :show="$showModal" :title="$priceId ? __('Edit price') : __('New price')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input name="name" :label="__('Name')" wire:model="name" autofocus />

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="is_sell" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                {{ __('Sell price') }}
            </label>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
