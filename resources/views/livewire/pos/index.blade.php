<div>
    <x-ui.page-header :title="__('Pos')" :subtitle="__('Manage point-of-sale terminals.')">
        <x-slot:actions>
            <x-ui.button wire:click="create">{{ __('New pos') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($poses->isEmpty())
        <x-ui.empty-state :title="__('No pos terminals yet')" :description="__('Create your first pos terminal to get started.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($poses as $pos)
                <x-ui.card>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-slate-900">{{ $pos->name }}</p>
                            <p class="text-sm text-slate-500">{{ $pos->shop?->name ?? __('No shop') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="secondary" wire:click="generateToken({{ $pos->id }})" wire:confirm="{{ __('Generating a new token immediately invalidates the previous one. Continue?') }}">{{ __('Generate token') }}</x-ui.button>
                            <x-ui.button variant="secondary" wire:click="edit({{ $pos->id }})">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button variant="danger" wire:click="delete({{ $pos->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
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
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($poses as $pos)
                        <tr>
                            <td class="px-4 py-3 text-sm text-slate-500">#{{ $pos->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $pos->name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $pos->shop?->name ?? __('No shop') }}</td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" wire:click="generateToken({{ $pos->id }})" wire:confirm="{{ __('Generating a new token immediately invalidates the previous one. Continue?') }}">{{ __('Generate token') }}</x-ui.button>
                                <x-ui.button variant="secondary" wire:click="edit({{ $pos->id }})">{{ __('Edit') }}</x-ui.button>
                                <x-ui.button variant="danger" wire:click="delete({{ $pos->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $poses->links() }}</div>
    @endif

    <x-ui.modal :show="$showModal" :title="$posId ? __('Edit pos') : __('New pos')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input name="name" :label="__('Name')" wire:model="name" autofocus />

            <x-ui.select name="shop_id" :label="__('Shop')" wire:model="shop_id">
                <option value="">{{ __('No shop') }}</option>
                @foreach($shops as $shop)
                    <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                @endforeach
            </x-ui.select>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal :show="$showTokenModal" :title="__('API token')" close="closeTokenModal">
        <div class="space-y-4">
            <p class="text-sm text-slate-600">
                {{ __('This is the only time this token will be shown. Copy it into the :pos POS terminal now — it cannot be retrieved again afterwards.', ['pos' => $generatedTokenPosName]) }}
            </p>

            <div>
                <x-ui.label for="generated-token">{{ __('Token') }}</x-ui.label>
                <input
                    id="generated-token"
                    type="text"
                    readonly
                    onclick="this.select()"
                    value="{{ $generatedToken }}"
                    class="block w-full rounded-lg border-slate-300 font-mono text-sm shadow-sm focus:border-brand-600 focus:ring-brand-600"
                />
            </div>

            <div class="flex justify-end">
                <x-ui.button type="button" wire:click="closeTokenModal">{{ __('Done') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
