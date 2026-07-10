<div>
    <x-ui.page-header :title="__('Items')" :subtitle="__('Manage the product catalog.')">
        <x-slot:actions>
            <x-ui.button wire:click="create">{{ __('New item') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <div class="flex-1">
            <x-ui.input name="search" :label="__('Search')" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search by name or mark') }}" />
        </div>
        <div class="sm:w-64">
            <x-ui.select name="groupFilter" :label="__('Group')" wire:model.live="groupFilter">
                <option value="">{{ __('All groups') }}</option>
                @foreach($groups as $group)
                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    @if($items->isEmpty())
        <x-ui.empty-state :title="__('No items found')" :description="__('Try adjusting your search or create a new item.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($items as $item)
                <x-ui.card>
                    <div class="flex items-center justify-between gap-2">
                        <a href="{{ route('items.show', $item) }}" class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-900">{{ $item->name }}</p>
                            <p class="text-sm text-slate-500">{{ $item->mark ?? '—' }} &middot; {{ $item->group?->name ?? __('No group') }}</p>
                        </a>
                        <div class="flex flex-shrink-0 items-center gap-2">
                            <x-ui.button variant="secondary" wire:click="edit({{ $item->id }})">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button variant="danger" wire:click="delete({{ $item->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
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
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Mark') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Category') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($items as $item)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                <a href="{{ route('items.show', $item) }}" class="hover:text-brand-700 hover:underline">{{ $item->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $item->mark ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $item->group?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $item->category?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" :href="route('items.show', $item)">{{ __('View') }}</x-ui.button>
                                <x-ui.button variant="secondary" wire:click="edit({{ $item->id }})">{{ __('Edit') }}</x-ui.button>
                                <x-ui.button variant="danger" wire:click="delete({{ $item->id }})" wire:confirm="{{ __('Are you sure you want to delete this?') }}">{{ __('Delete') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $items->links() }}</div>
    @endif

    <x-ui.modal :show="$showModal" :title="$itemId ? __('Edit item') : __('New item')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input name="name" :label="__('Name')" wire:model="name" autofocus />
            <x-ui.input name="mark" :label="__('Mark')" wire:model="mark" />

            <x-ui.select name="group_id" :label="__('Group')" wire:model="group_id">
                <option value="">{{ __('No group') }}</option>
                @foreach($groups as $group)
                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="category_id" :label="__('Category')" wire:model="category_id">
                <option value="">{{ __('No category') }}</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input name="class_code" :label="__('Class code')" wire:model="class_code" />
            <x-ui.input name="package_code" :label="__('Package code')" wire:model="package_code" />

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
