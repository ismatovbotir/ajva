<div>
    <x-ui.page-header :title="__('Shops')" :subtitle="__('Manage the shops known to the system.')">
        <x-slot:actions>
            <x-ui.button wire:click="create">{{ __('New shop') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($shops->isEmpty())
        <x-ui.empty-state :title="__('No shops yet')" :description="__('Create your first shop to get started.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($shops as $shop)
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-slate-900">{{ $shop->name }}</p>
                            <p class="text-sm text-slate-500">#{{ $shop->id }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="secondary" wire:click="edit({{ $shop->id }})">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button variant="danger" wire:click="delete({{ $shop->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
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
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($shops as $shop)
                        <tr>
                            <td class="px-4 py-3 text-sm text-slate-500">#{{ $shop->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $shop->name }}</td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" wire:click="edit({{ $shop->id }})">{{ __('Edit') }}</x-ui.button>
                                <x-ui.button variant="danger" wire:click="delete({{ $shop->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $shops->links() }}</div>
    @endif

    <x-ui.modal :show="$showModal" :title="$shopId ? __('Edit shop') : __('New shop')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input name="name" :label="__('Name')" wire:model="name" autofocus />

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
